"""Ссылки проекта на один референс: где он используется и перенос номеров строк на новый коммит.

    python refs.py usage <корень проекта> <репозиторий>
    python refs.py remap <корень проекта> <репозиторий> <старый коммит> <новый коммит> [--notes docs/notes/<файл>.md] [--apply]

<репозиторий> — имя каталога в references/ (например hermes-dreaming).

usage  — сколько ссылок `ref: references/<репозиторий>/…` на какой файл референса и из каких файлов проекта:
         это карта того, что проект взял из референса.
remap  — переводит ссылки вида `references/<репозиторий>/<файл>@<старый>:L10-L20` на новый коммит, пересчитывая
         строки по диффу файла между коммитами. С --notes так же пересчитывает короткие ссылки конспекта
         (`dream.py:L10-L20`, без коммита; имя файла ищется в референсе по пути или по уникальному имени).
         Без --apply только показывает, что изменится.

Строка, которой в новом файле нет, берётся по ближайшей уцелевшей соседней (до 5 строк); если и таких нет — ссылка
остаётся как была и попадает в список «проверить вручную». Скрипт не знает, изменился ли смысл места: ссылки на
файлы, которые менялись между коммитами, после пересчёта надо открыть и прочитать.
"""
import difflib
import re
import subprocess
import sys
from pathlib import Path

SCAN = ['src', 'test', 'bootstrap', 'bin', 'brief', 'docs/notes', 'packages', 'services', 'mcp', 'skills']
SKIP = {'vendor', 'node_modules', 'var', 'storage', '.git', 'graphify-out'}
SUFFIXES = {'.php', '.md', '.sh', '.yml', '.yaml', '.json', '.js', '.ts', '.py'}


def files(root):
    for base in SCAN:
        d = root / base
        if d.exists():
            for p in d.rglob('*'):
                if p.is_file() and p.suffix in SUFFIXES and not SKIP.intersection(p.parts):
                    yield p
    for name in ('CLAUDE.md', 'BRIEF.md'):
        if (root / name).exists():
            yield root / name


def read(p):
    try:
        return p.read_bytes().decode('utf-8')
    except UnicodeDecodeError:
        return None


def usage(root, repo):
    link = re.compile(r'references/' + re.escape(repo) + r'/([\w./-]+?)(?:@([0-9a-f]{6,40}))?(?=[:\s`)\]]|$)')
    by_target, by_source, commits = {}, {}, {}
    for p in files(root):
        text = read(p)
        if text is None or ('references/' + repo) not in text:
            continue
        for m in link.finditer(text):
            target = m.group(1).rstrip('.')
            by_target[target] = by_target.get(target, 0) + 1
            by_source.setdefault(str(p.relative_to(root)).replace('\\', '/'), set()).add(target)
            commits[m.group(2) or '(без коммита)'] = commits.get(m.group(2) or '(без коммита)', 0) + 1
    print('Коммиты в ссылках:', ', '.join(f'{c}: {n}' for c, n in sorted(commits.items(), key=lambda x: -x[1])) or 'ссылок нет')
    print('\nФайлы референса, на которые ссылается проект:')
    for target, n in sorted(by_target.items(), key=lambda x: -x[1]):
        print(f'  {n:3d}  {target}')
    print('\nГде в проекте:')
    for source, targets in sorted(by_source.items()):
        print(f'  {source}: {", ".join(sorted(targets))}')


def remap(root, repo, old, new, notes, apply):
    sub = root / 'references' / repo
    maps, problems = {}, []

    def show(commit, name):
        r = subprocess.run(['git', '-C', str(sub), 'show', f'{commit}:{name}'], capture_output=True)
        return r.stdout.decode('utf-8', 'replace').splitlines() if r.returncode == 0 else None

    tree = subprocess.run(['git', '-C', str(sub), 'ls-tree', '-r', '--name-only', old], capture_output=True).stdout.decode().split('\n')

    def resolve(name):
        if name in tree:
            return name
        found = [t for t in tree if t.endswith('/' + name)]
        return found[0] if len(found) == 1 else None

    def mapping(name):
        if name not in maps:
            a, b = show(old, name), show(new, name)
            if a is None or b is None:
                maps[name] = None
            else:
                m = {}
                for tag, i1, i2, j1, j2 in difflib.SequenceMatcher(None, a, b, autojunk=False).get_opcodes():
                    if tag == 'equal':
                        for k in range(i2 - i1):
                            m[i1 + k + 1] = j1 + k + 1
                maps[name] = m
        return maps[name]

    def move(name, line, where):
        m = mapping(name)
        if m is None:
            problems.append(f'{where}: файла {name} нет в одном из коммитов')
            return None
        if line in m:
            return m[line]
        for d in range(1, 6):
            if line + d in m:
                return max(1, m[line + d] - d)
            if line - d in m:
                return m[line - d] + d
        problems.append(f'{where}: {name}:L{line} — строки больше нет, проверить вручную')
        return None

    def ranges(name, text, where):
        def one(mm):
            a = move(name, int(mm.group(1)), where)
            b = move(name, int(mm.group(2)), where) if mm.group(2) else None
            if a is None or (mm.group(2) and b is None):
                return mm.group(0)
            return f'L{a}-L{b}' if mm.group(2) else f'L{a}'
        return re.sub(r'L(\d+)(?:-L?(\d+))?', one, text)

    lines = r'(?::L\d+(?:-L?\d+)?(?:, L\d+(?:-L?\d+)?)*)?'
    code = re.compile(r'(references/' + re.escape(repo) + r'/)([\w./-]+?)@' + re.escape(old) + '(' + lines + ')')
    # в тексте ссылка может продолжаться вторым файлом того же репозитория без пути: «…; dream_lang.py@<старый>:L1-L5»
    bare = re.compile(r'(?<![\w/])([\w.-]+\.\w+)@' + re.escape(old) + '(' + lines + ')')
    short = re.compile(r'(?<![\w/@])((?:[\w-]+/)*[\w-]+\.(?:py|md|yaml|yml|toml|json|php|ts|js|go|sh)):(L\d+(?:-L?\d+)?(?:, L\d+(?:-L?\d+)?)*)')
    changed = 0
    for p in files(root):
        raw = read(p)
        if raw is None or repo not in raw:
            continue
        where = str(p.relative_to(root)).replace('\\', '/')
        text = code.sub(lambda mm: mm.group(1) + mm.group(2) + '@' + new + ranges(mm.group(2), mm.group(3), where), raw)

        def bare_one(mm):
            name = resolve(mm.group(1))
            return mm.group(0) if name is None else mm.group(1) + '@' + new + ranges(name, mm.group(2), where)
        text = bare.sub(bare_one, text)
        if notes and where == notes:
            def short_one(mm):
                name = resolve(mm.group(1))
                return mm.group(0) if name is None or mapping(name) is None else mm.group(1) + ':' + ranges(name, mm.group(2), where)
            text = short.sub(short_one, text)
        if text != raw:
            changed += 1
            print(('изменён ' if apply else 'изменится ') + where)
            if apply:
                p.write_bytes(text.encode('utf-8'))
    print(f'\nФайлов: {changed}' + ('' if apply else ' (пробный прогон, добавьте --apply)'))
    if problems:
        print('\nПроверить вручную:\n  ' + '\n  '.join(sorted(set(problems))))
    left = subprocess.run(['git', '-C', str(root), 'grep', '-n', '-I', old, '--', *[s for s in SCAN if (root / s).exists()]], capture_output=True).stdout.decode('utf-8', 'replace')
    if apply and left.strip():
        print('\nСтарый коммит ещё упоминается (frontmatter конспекта, текст без пути и т. п.):\n' + left[:3000])


def main():
    if len(sys.argv) < 4 or sys.argv[1] not in ('usage', 'remap'):
        print(__doc__)
        sys.exit(2)
    root, repo = Path(sys.argv[2]).resolve(), sys.argv[3]
    if not (root / 'references' / repo).exists():
        sys.exit(f'нет каталога references/{repo}')
    if sys.argv[1] == 'usage':
        usage(root, repo)
        return
    if len(sys.argv) < 6:
        print(__doc__)
        sys.exit(2)
    notes = sys.argv[sys.argv.index('--notes') + 1].replace('\\', '/') if '--notes' in sys.argv else None
    remap(root, repo, sys.argv[4], sys.argv[5], notes, '--apply' in sys.argv)


if __name__ == '__main__':
    sys.stdout.reconfigure(encoding='utf-8')
    main()
