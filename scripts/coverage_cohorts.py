"""Union measured source coverage from independently validated LLVM cohorts.

No profile counters are invented. Raw exports remain authoritative; this module
computes source coverage unions and preserves distinct generic instantiations.
Every input's source summaries are independently reconstructed and checked first.
"""
from collections import defaultdict
from copy import deepcopy
from pathlib import Path


def branch_counts(branches):
    measured = {}
    for branch in branches:
        if len(branch) != 9:
            raise ValueError('Unsupported LLVM branch record')
        # File identifiers are local to a function; coordinates are already
        # source-file-local in LLVM's per-file coverage view.
        key = (*branch[:4], branch[8])
        previous = measured.get(key, (0, 0))
        measured[key] = (previous[0] + branch[4], previous[1] + branch[5])
    return measured


def logical_function(function):
    if not function['regions']:
        raise ValueError('Function has no source regions')
    first = function['regions'][0]
    return (str(Path(function['filenames'][first[5]]).resolve()), *first[:2])


def geometry(function):
    files = function['filenames']
    def region(record, branch=False):
        if len(record) != (9 if branch else 8):
            raise ValueError('Unsupported LLVM function region')
        file_index, expanded_index = (6, 7) if branch else (5, 6)
        return (*record[:4], str(Path(files[record[file_index]]).resolve()),
                str(Path(files[record[expanded_index]]).resolve()), record[-1])
    return (tuple(region(item) for item in function['regions']),
            tuple(region(item, True) for item in function.get('branches', [])))


def summary(counts):
    return {'count': len(counts), 'covered': sum(value > 0 for value in counts.values())}


def merge_reports(reports, expected, canonical=lambda name: name):
    expected = {str(Path(path).resolve()) for path in expected}
    union_lines = defaultdict(dict)
    union_branches = defaultdict(dict)
    union_functions = defaultdict(dict)
    instances = {}
    seen = set()
    for report in reports:
        if report.get('type') != 'llvm.coverage.json.export' or len(report.get('data', [])) != 1:
            raise ValueError('Expected one raw LLVM export dataset per cohort')
        dataset = report['data'][0]
        functions = defaultdict(dict)
        function_lines = defaultdict(dict)
        for function in dataset['functions']:
            key = logical_function(function)
            filename = key[0]
            if filename not in expected:
                continue
            functions[filename][key] = max(functions[filename].get(key, 0), function['count'])
            # LLVM file summaries sum logical-function line summaries, so
            # separate closures sharing a physical line remain distinct.
            for region in function['regions']:
                if region[-1] != 0 or str(Path(function['filenames'][region[5]]).resolve()) != filename:
                    continue
                for line in range(region[0], region[2] - (region[3] == 1) + 1):
                    identity = (key, line)
                    function_lines[filename][identity] = max(function_lines[filename].get(identity, 0), region[4])
            name = canonical(function['name'])
            instance_key = (key, name)
            shape = geometry(function)
            if instance_key in instances:
                prior = instances[instance_key]
                if prior['geometry'] != shape:
                    raise ValueError(f'Incompatible coverage boundaries for {name} at {key}')
                prior['count'] += function['count']
                for target, source in zip(prior['regions'], function['regions']):
                    target[4] += source[4]
                for target, source in zip(prior['branches'], function.get('branches', [])):
                    target[4] += source[4]
                    target[5] += source[5]
                prior['raw_names'].add(function['name'])
            else:
                instances[instance_key] = {'geometry': shape, 'count': function['count'],
                    'regions': deepcopy(function['regions']), 'branches': deepcopy(function.get('branches', [])),
                    'filenames': list(function['filenames']), 'raw_names': {function['name']}}
        cohort_files = set()
        for entry in dataset['files']:
            filename = str(Path(entry['filename']).resolve())
            if filename not in expected:
                continue
            if filename in cohort_files:
                raise ValueError(f'Duplicate cohort source file: {filename}')
            cohort_files.add(filename)
            seen.add(filename)
            lines = function_lines[filename]
            branches = branch_counts(entry.get('branches', []))
            counts = {'lines': lines, 'functions': functions[filename],
                      'branches': {(key, side): value for key, values in branches.items()
                                   for side, value in enumerate(values)}}
            for metric, values in counts.items():
                actual = summary(values)
                declared = entry['summary'].get(metric, {})
                if any(declared.get(field) != value for field, value in actual.items()):
                    raise ValueError(f'Cannot reconstruct LLVM {metric} summary for {filename}: {actual} != {declared}')
            for line, count in lines.items():
                union_lines[filename][line] = max(union_lines[filename].get(line, 0), count)
            for branch, values in branches.items():
                previous = union_branches[filename].get(branch, (0, 0))
                union_branches[filename][branch] = (previous[0] + values[0], previous[1] + values[1])
            for key, count in functions[filename].items():
                union_functions[filename][key] = max(union_functions[filename].get(key, 0), count)
    if expected - seen:
        raise ValueError('First-party source absent from cohort exports: ' + ', '.join(sorted(expected - seen)))
    files = []
    for filename in sorted(expected):
        branches = union_branches[filename]
        metrics = {'lines': summary(union_lines[filename]), 'functions': summary(union_functions[filename]),
                   'branches': summary({(key, side): value for key, values in branches.items()
                                        for side, value in enumerate(values)})}
        files.append({'filename': filename, 'summary': metrics,
                      'measured_lines': [{'function': list(key), 'line': line, 'count': count}
                                         for (key, line), count in sorted(union_lines[filename].items())],
                      'measured_branches': [{'coordinates': list(key), 'counts': list(values)}
                                            for key, values in sorted(branches.items())]})
    totals = {metric: {field: sum(entry['summary'][metric][field] for entry in files)
                       for field in ('count', 'covered')} for metric in ('lines', 'functions', 'branches')}
    instance_data = [{'source_identity': list(key), 'name': name,
                      **{field: value for field, value in value.items() if field != 'geometry'},
                      'raw_names': sorted(value['raw_names'])}
                     for (key, name), value in sorted(instances.items())]
    return {'type': 'php-profiler.coverage.cohort-union', 'version': 1,
            'method': 'Source coverage OR across strict LLVM cohorts; per-instantiation measured counters summed',
            'data': [{'files': files, 'totals': totals}], 'instantiations': instance_data}
