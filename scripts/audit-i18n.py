#!/usr/bin/env python3
"""
i18n Localization Audit — VoxelBooking

Finds user-facing text that bypasses the translation layer, and checks the
language files against the code.

Scanned (paths relative to the project root, no fixed file list):

  templates/**/*.php   text nodes, attributes (placeholder, aria-label, title,
                       alt, data-confirm-text, meta description ...), label-like
                       PHP array entries, English-only date formats, inline
                       <script> blocks
  app/**/*.php         user-facing server messages (toasts, validation errors,
                       HTML error pages, message/error entries), English-only
                       date formats, and the JS embedded in heredocs
  resources/js/**/*.js hardcoded strings
  lang/                keys used in code but missing from lang/en (error) and
                       locale parity with lang/en (warning)

Heuristics are deliberately strict about what counts as "text" (a capitalized
word, or at least two words) but they are heuristics: silence an intentional
finding with an entry in IGNORE below, or with an "i18n-ignore" comment on the
same line.

Usage:
    python3 scripts/audit-i18n.py              # findings + summary
    python3 scripts/audit-i18n.py --verbose    # also list the clean files
    python3 scripts/audit-i18n.py --strict     # warnings (locale parity) fail too
    python3 scripts/audit-i18n.py --include-api  # also report English inside Response::json()
    python3 scripts/audit-i18n.py --root PATH  # audit another checkout
    python3 -m unittest discover -s tests/scripts   # tests of this script

Exit code: 0 when clean, 1 when errors are found (or warnings with --strict),
2 when the project root cannot be found.
"""

import argparse
import os
import re
import sys
from collections import namedtuple

ERROR = 'error'
WARNING = 'warning'

Finding = namedtuple('Finding', 'severity category path line text')

# ── Intentional English ──────────────────────────────────────────────────────
# (path regex, text regex, reason[, file-content regex]). A finding is dropped
# when the path and the text match and, if given, the file also matches the
# last pattern. Keep the reason: it is the documentation of the exception.
IGNORE = [
    (r'^app/Controllers/AgentApi/', r'.',
     'Agent API: JSON contract for machine clients, English by design'),
    (r'^app/Middleware/AgentAuthMiddleware\.php$', r'.',
     'Agent API: JSON contract for machine clients, English by design'),
    (r'^app/Engine/Locale\.php$', r'"F"',
     'English month name, only used when booking.months.* has no translation'),
    (r'^app/Engine/Mailer\.php$', r'No operator email provided',
     'Internal result string that is logged, never displayed'),
    (r'^resources/js/admin/[\w-]+\.js$',
     r'This field is required|Please enter a valid value|At least :min characters'
     r'|Please match the expected format|Invalid value|^Confirm$|^Are you sure\?$',
     'Last-resort English defaults, used only when the server-rendered '
     'window.__VB_ADMIN_I18N__ payload is missing',
     r'__VB_ADMIN_I18N__'),
    (r'^templates/admin/tenants/(create|users/invite)\.php$',
     r'placeholder="(Acme Hair Studio|Jane Doe)"',
     'Example data in a placeholder'),
]

# First words that are product/technical names rather than translatable text.
TECHNICAL_FIRST_WORDS = frozenset({
    'VoxelBooking', 'UTF', 'SSL', 'TLS', 'PHP', 'MySQL', 'PDO', 'SMTP', 'JSON',
    'HTML', 'CSS', 'POST', 'GET', 'None', 'Lucide', 'PRD', 'WOFF2', 'Inter',
    'Helvetica', 'SansSerif', 'Copyright', 'Accept', 'Content', 'Alpine',
    'Resend', 'ICS', 'GDPR', 'CSV', 'URL', 'API', 'IP',
    # Fonts
    'Segoe', 'Roboto', 'BlinkMacSystemFont',
    # KeyboardEvent.key values
    'Escape', 'Enter', 'Tab', 'Backspace', 'Home', 'End', 'PageUp', 'PageDown',
    'ArrowUp', 'ArrowDown', 'ArrowLeft', 'ArrowRight',
})

# PHP array keys whose string value is shown to users.
LABEL_KEYS = (
    'label', 'desc', 'description', 'title', 'heading', 'subtitle', 'hint',
    'placeholder', 'text', 'subject', 'cta_label', 'body_intro', 'body_outro',
)

# Attributes whose value is shown to users or read by assistive technology.
TEXT_ATTRIBUTES = (
    'placeholder', 'aria-label', 'aria-description', 'title', 'alt', 'label',
    'data-confirm', 'data-confirm-text', 'data-label',
)

# ── Text heuristics ──────────────────────────────────────────────────────────

PHP_BLOCK = re.compile(r'<\?(?:=|php)?.*?\?>', re.S)
JS_TEMPLATE_EXPR = re.compile(r'\$\{[^}]*\}')


def strip_dynamic(text):
    """Remove PHP blocks and JS ${...} expressions."""
    return JS_TEMPLATE_EXPR.sub(' ', PHP_BLOCK.sub(' ', text))


def is_human_text(text):
    """True when `text` looks like a sentence/label a person would read."""
    s = strip_dynamic(text).strip()
    if len(s) < 3 or not re.search(r'[A-Za-z]{3,}', s):
        return False
    if re.match(r'^[\w.+-]+@[\w-]+(\.[\w-]+)+$', s):          # e-mail address
        return False
    if re.match(r'^(https?:)?//|^[/.#]', s):                   # URL / path / selector
        return False
    if re.match(r'^[a-z0-9_.:/#?=&%@+\-]+$', s):               # identifier, domain, token
        return False
    first = re.split(r'[\s,.:;]', s, maxsplit=1)[0]
    if first in TECHNICAL_FIRST_WORDS:
        return False
    return bool(re.match(r'^[A-Z][a-z]', s) or re.search(r'\b[A-Za-z]{2,}\s+[A-Za-z]{2,}', s))


def is_message(text):
    """Stricter test for server messages: a sentence, not an error code."""
    s = text.strip()
    return is_human_text(s) and bool(re.search(r'\s|[.!?]$', s)) and s[0].isupper()


# ── Line-level detectors (pure functions: line in, [(category, text)] out) ───

ATTRIBUTE = re.compile(
    r'\b(' + '|'.join(re.escape(a) for a in TEXT_ATTRIBUTES) + r')\s*=\s*"([^"]*)"')
META_DESCRIPTION = re.compile(r'<meta\b[^>]*\bname="description"[^>]*\bcontent="([^"]*)"', re.I)
TEXT_NODE = re.compile(r'>([^<>]*)<')
LABEL_ENTRY = re.compile(
    r'''['"](?:''' + '|'.join(LABEL_KEYS) + r''')['"]\s*=>\s*(['"])((?:\\.|(?!\1).)*)\1''')
DATE_CALL = re.compile(r'''\b(?:date|gmdate|format)\(\s*(['"])((?:\\.|(?!\1).)*)\1''')

TOAST = re.compile(r'''toast\(\s*['"][a-z]+['"]\s*,\s*(['"])((?:\\.|(?!\1).)*)\1''')
ERRORS_APPEND = re.compile(r'''\$errors\[\]\s*=\s*(['"])((?:\\.|(?!\1).)*)\1\s*;''')
MESSAGE_ENTRY = re.compile(
    r'''['"](?:error|message|reason|flash|warning)['"]\s*=>\s*(['"])((?:\\.|(?!\1).)*)\1''')
HTML_ERROR_PAGE = re.compile(r'''Response::html\(\s*['"]<h\d>([^<]*)</h\d>''')

JS_ASSIGNED = re.compile(
    r'''(?:=|:|\|\||\?\?|\(|,|\breturn)\s*(['"])([A-Z][a-z](?:\\.|(?!\1).){4,})\1''')
JS_TEXT_CALL = re.compile(r'''\b(?:t|__)\(''')


def detect_html(line):
    """Hardcoded text in markup: text nodes, attributes, meta description.

    PHP blocks are removed first: text between `?>` and `<?` is part of a tag,
    not a text node, and dynamic values are translated where they are built.
    """
    line = PHP_BLOCK.sub(' ', line)
    found = []
    for m in META_DESCRIPTION.finditer(line):
        if is_human_text(m.group(1)):
            found.append(('attribute', 'meta description="%s"' % m.group(1)))
    for m in ATTRIBUTE.finditer(line):
        if is_human_text(m.group(2)):
            found.append(('attribute', '%s="%s"' % (m.group(1), m.group(2))))
    for m in TEXT_NODE.finditer(line):
        if is_human_text(m.group(1)) and not re.search(r'&[lg]t;', m.group(1)):
            found.append(('text', m.group(1).strip()))
    return found


def detect_php(line):
    """Hardcoded text in PHP code: label-like array entries, date formats."""
    found = []
    for m in LABEL_ENTRY.finditer(line):
        if is_human_text(m.group(2)):
            found.append(('label', m.group(0).strip()))
    for m in DATE_CALL.finditer(line):
        fmt = re.sub(r'\\.', '', m.group(2))                   # drop escaped literals
        tokens = sorted(set(re.findall(r'[FMDl]', fmt)))
        if tokens:
            found.append(('date', 'English-only date token(s) %s in "%s"'
                          % (','.join(tokens), m.group(2))))
    return found


def detect_server_message(line):
    """User-facing messages built in controllers, middleware and the engine."""
    found = []
    for pattern in (TOAST, ERRORS_APPEND, MESSAGE_ENTRY):
        for m in pattern.finditer(line):
            if is_message(m.group(2)):
                found.append(('message', m.group(0).strip()))
    for m in HTML_ERROR_PAGE.finditer(line):
        if is_human_text(m.group(1)):
            found.append(('message', m.group(0).strip()))
    return found


def detect_js(line):
    """Hardcoded strings in JavaScript: markup in strings, assigned literals."""
    if JS_TEXT_CALL.search(line) or re.search(r'\bconsole\.', line):   # t() call, developer output
        return []
    line = PHP_BLOCK.sub(' ', line)
    found = []
    for m in ATTRIBUTE.finditer(line):
        if is_human_text(m.group(2)):
            found.append(('attribute', '%s="%s"' % (m.group(1), m.group(2))))
    for m in TEXT_NODE.finditer(line):
        if is_human_text(m.group(1)) and not re.search(r'[+|"{}=\\]', m.group(1)):
            found.append(('text', m.group(1).strip()))
    for m in JS_ASSIGNED.finditer(line):
        if is_human_text(m.group(2)):
            found.append(('string', m.group(2)))
    return found


# ── File-level scanning ──────────────────────────────────────────────────────

COMMENT_START = ('//', '*', '/*', '#', '<!--')


def read_lines(path):
    with open(path, encoding='utf-8', errors='replace') as fh:
        return fh.read().splitlines()


def walk(base, extension):
    for dirpath, dirnames, filenames in os.walk(base):
        dirnames.sort()
        for name in sorted(filenames):
            if name.endswith(extension):
                yield os.path.join(dirpath, name)


def scan_template(lines, app=False):
    """Yield (line_number, category, text) for a template or a PHP source file.

    Tracks <style>, <script> and heredoc JS blocks so that each line gets the
    right set of rules. In app/ code (`app=True`) the markup rules only apply to
    lines that actually contain a tag, so comparisons such as `$a > 5 && $b < 3`
    are not mistaken for text nodes.
    """
    in_style = in_script = in_heredoc_js = in_php = False
    for number, line in enumerate(lines, 1):
        stripped = line.strip()
        lower = stripped.lower()

        if 'i18n-ignore' in line or not stripped:
            continue

        # Block state
        if in_style:
            in_style = '</style' not in lower
            continue
        if in_heredoc_js:
            if re.match(r'^JS;?$', stripped):
                in_heredoc_js = False
                continue
            for category, text in detect_js(line):
                yield number, category, text
            continue
        if re.search(r'<<<[\'"]?JS[\'"]?\s*$', stripped):
            in_heredoc_js = True
            continue
        if '<style' in lower and '</style' not in lower:
            in_style = True
            continue

        if in_script or '<script' in lower:
            inner = line
            if '<script' in lower:
                inner = re.sub(r'<script\b[^>]*>', '', line, flags=re.I)
            if '</script' in lower:
                inner = re.sub(r'</script\s*>.*$', '', inner, flags=re.I)
                in_script = False
            elif '<script' in lower:
                in_script = True
            if inner.strip() and not inner.strip().startswith(COMMENT_START):
                for category, text in detect_js(inner):
                    yield number, category, text
            continue

        if stripped.startswith(COMMENT_START):
            continue

        # Multi-line <?php ... ?> block in a template: PHP rules only. (A whole
        # app/ file is PHP, but its strings may hold markup, so it keeps both.)
        if in_php:
            in_php = '?>' not in line
            for category, text in detect_php(line):
                yield number, category, text
            continue
        markup = line
        if not app and re.search(r'<\?php\b(?!.*\?>)', line):
            in_php = True
            markup = line.split('<?php', 1)[0]

        if not app or re.search(r'<[A-Za-z/]', line):
            for category, text in detect_html(markup):
                yield number, category, text
        for category, text in detect_php(line):
            yield number, category, text


def in_json_response(lines, index):
    """True when line `index` belongs to a Response::json(...) call."""
    for back in range(7):
        i = index - back
        if i < 0:
            return False
        if back > 0 and ';' in lines[i]:                       # the previous statement ended
            return False
        if 'Response::json(' in lines[i]:
            return True
    return False


def scan_php_source(lines, include_api=False):
    """Like scan_template(), plus the server-message rules for app/ code.

    Messages inside Response::json() are the API contract for machine clients
    and stay in English; they are only reported (as warnings, category
    "api-message") when `include_api` is set.
    """
    messages = []
    for index, line in enumerate(lines):
        stripped = line.strip()
        if ('i18n-ignore' in line or stripped.startswith(COMMENT_START)
                or re.search(r'Logger::|error_log\(|throw new|Exception\(', line)):
            continue
        for category, text in detect_server_message(line):
            if in_json_response(lines, index):
                if include_api:
                    messages.append((index + 1, 'api-message', text))
                continue
            messages.append((index + 1, category, text))

    # A line reported as a message is not reported again as a text node
    message_lines = {number for number, _category, _text in messages}
    for number, category, text in scan_template(lines, app=True):
        if not (category == 'text' and number in message_lines):
            yield number, category, text
    for hit in messages:
        yield hit


def is_ignored(path, text, source=''):
    """True when an IGNORE entry covers this finding (`source` = the file's text)."""
    for path_re, text_re, _reason, *context in IGNORE:
        if re.search(path_re, path) and re.search(text_re, text):
            if not context or re.search(context[0], source):
                return True
    return False


def scan_js(lines):
    for number, line in enumerate(lines, 1):
        stripped = line.strip()
        if 'i18n-ignore' in line or not stripped or stripped.startswith(COMMENT_START):
            continue
        for category, text in detect_js(line):
            yield number, category, text


# ── Language files ───────────────────────────────────────────────────────────

class LangParseError(Exception):
    pass


TOKEN = re.compile(r'''
    (?P<ws>\s+|//[^\n]*|\#[^\n]*|/\*.*?\*/)
  | (?P<sq>'(?:\\.|[^'\\])*')
  | (?P<dq>"(?:\\.|[^"\\])*")
  | (?P<num>-?\d+)
  | (?P<arrow>=>)
  | (?P<word>[A-Za-z_][A-Za-z_0-9]*)
  | (?P<sym>[\[\](),;.])
''', re.X | re.S)


def _unescape(raw, quote):
    body = raw[1:-1]
    if quote == "'":
        return body.replace("\\'", "'").replace('\\\\', '\\')
    simple = {'n': '\n', 't': '\t', 'r': '\r', '\\': '\\', '"': '"', '$': '$'}
    return re.sub(r'\\(.)', lambda m: simple.get(m.group(1), m.group(0)), body, flags=re.S)


def parse_lang_file(source):
    """Parse a lang/*.php file (`return [ 'key' => 'value', ... ];`) into a dict.

    Supports the subset the translation files use: nested arrays, string and
    integer keys, single/double quoted strings, `.` concatenation and comments.
    """
    tokens = [(m.lastgroup, m.group()) for m in TOKEN.finditer(source) if m.lastgroup != 'ws']
    # Skip "<?php declare(strict_types=1);" and anything else before `return`
    start = next((i for i, (k, v) in enumerate(tokens) if k == 'word' and v == 'return'), None)
    if start is None:
        raise LangParseError('no "return" statement')
    pos = [start + 1]

    def peek():
        return tokens[pos[0]] if pos[0] < len(tokens) else (None, None)

    def take(kind=None, value=None):
        tok = peek()
        if tok[0] is None or (kind and tok[0] != kind) or (value and tok[1] != value):
            raise LangParseError('unexpected token %r' % (tok[1],))
        pos[0] += 1
        return tok

    def string():
        kind, raw = take()
        out = _unescape(raw, raw[0])
        while peek() == ('sym', '.'):                          # 'a' . 'b'
            take()
            kind, raw = take()
            out += _unescape(raw, raw[0])
        return out

    def value():
        kind, raw = peek()
        if kind in ('sq', 'dq'):
            return string()
        if kind == 'num':
            take()
            return int(raw)
        if kind == 'word' and raw.lower() in ('true', 'false', 'null'):
            take()
            return raw.lower()
        if (kind, raw) == ('sym', '[') or (kind == 'word' and raw.lower() == 'array'):
            close = ']'
            if kind == 'word':
                take()
                take('sym', '(')
                close = ')'
            else:
                take()
            result, index = {}, 0
            while peek() != ('sym', close):
                item = value()
                if peek() == ('arrow', '=>'):
                    take()
                    key, item = item, value()
                    if isinstance(key, int):
                        index = max(index, key + 1)
                else:
                    key, index = index, index + 1
                result[str(key)] = item
                if peek() == ('sym', ','):
                    take()
                else:
                    break
            take('sym', close)
            return result
        raise LangParseError('unexpected token %r' % (raw,))

    data = value()
    if not isinstance(data, dict):
        raise LangParseError('"return" is not an array')
    return data


def flatten(data, prefix=''):
    """{'a': {'b': 'x'}} -> {'a.b': 'x'} (leaves only)."""
    out = {}
    for key, val in data.items():
        full = prefix + key
        if isinstance(val, dict):
            out.update(flatten(val, full + '.'))
        else:
            out[full] = val
    return out


def load_lang(root, findings):
    """Return {locale: {domain: {flat_key: value}}} for every lang/<locale>/*.php."""
    lang_dir = os.path.join(root, 'lang')
    catalog = {}
    if not os.path.isdir(lang_dir):
        return catalog
    for locale in sorted(os.listdir(lang_dir)):
        locale_dir = os.path.join(lang_dir, locale)
        if not os.path.isdir(locale_dir):
            continue
        for path in walk(locale_dir, '.php'):
            domain = os.path.splitext(os.path.basename(path))[0]
            relpath = os.path.relpath(path, root).replace(os.sep, '/')
            try:
                with open(path, encoding='utf-8', errors='replace') as fh:
                    catalog.setdefault(locale, {})[domain] = flatten(parse_lang_file(fh.read()))
            except LangParseError as exc:
                findings.append(Finding(ERROR, 'lang', relpath, 0, 'cannot parse: %s' % exc))
    return catalog


PHP_KEY_CALL = re.compile(
    r'''(?:\b__p?|Locale::(?:translate|plural))\(\s*(['"])([a-z][a-z0-9_]*(?:\.[A-Za-z0-9_]+)+)\1\s*[,)]''')
JS_KEY_CALL = re.compile(r'''(?<![\w.])t\(\s*(['"])([a-z][a-z0-9_]*(?:\.[A-Za-z0-9_]+)*)\1\s*[,)]''')


def used_keys(lines, javascript):
    """Yield (line_number, full_key) for translation calls with a literal key."""
    for number, line in enumerate(lines, 1):
        for m in PHP_KEY_CALL.finditer(line):
            yield number, m.group(2)
        if javascript:
            for m in JS_KEY_CALL.finditer(line):
                yield number, 'booking.' + m.group(2)          # the JS payload is the booking domain


def placeholders(value):
    return sorted(set(re.findall(r':[a-z_]+', value))) if isinstance(value, str) else []


def check_parity(catalog, findings):
    """lang/<locale> must mirror lang/en (warnings: missing strings fall back to English)."""
    english = catalog.get('en', {})
    for locale, domains in sorted(catalog.items()):
        if locale == 'en':
            continue
        for domain, en_keys in sorted(english.items()):
            path = 'lang/%s/%s.php' % (locale, domain)
            keys = domains.get(domain)
            if keys is None:
                findings.append(Finding(WARNING, 'parity', path, 0, 'file missing'))
                continue
            for key in sorted(set(en_keys) - set(keys)):
                findings.append(Finding(WARNING, 'parity', path, 0, 'missing key %s' % key))
            for key in sorted(set(keys) - set(en_keys)):
                findings.append(Finding(WARNING, 'parity', path, 0, 'key not in lang/en: %s' % key))
            for key in sorted(set(keys) & set(en_keys)):
                if placeholders(keys[key]) != placeholders(en_keys[key]):
                    findings.append(Finding(WARNING, 'parity', path, 0,
                                            'placeholders differ for %s' % key))


# ── Driver ───────────────────────────────────────────────────────────────────

def audit(root, include_api=False):
    """Run every check. Returns (findings, files_scanned, clean_files)."""
    findings = []
    seen = set()
    scanned = []
    used = []                                                  # (path, line, key)

    def relative(path):
        return os.path.relpath(path, root).replace(os.sep, '/')

    surfaces = (
        ('templates', '.php', scan_template, False),
        ('app', '.php', scan_php_source, False),
        ('resources/js', '.js', scan_js, True),
    )
    for subdir, extension, scanner, javascript in surfaces:
        for path in walk(os.path.join(root, subdir), extension):
            rel = relative(path)
            lines = read_lines(path)
            source = '\n'.join(lines)
            scanned.append(rel)
            hits = scanner(lines, include_api) if scanner is scan_php_source else scanner(lines)
            for number, category, text in hits:
                if is_ignored(rel, text, source) or (rel, number, text) in seen:
                    continue
                seen.add((rel, number, text))
                severity = WARNING if category == 'api-message' else ERROR
                findings.append(Finding(severity, category, rel, number, text))
            in_js_context = javascript or rel.startswith('templates/booking/')
            for number, key in used_keys(lines, in_js_context):
                used.append((rel, number, key))

    catalog = load_lang(root, findings)
    english = catalog.get('en')
    if english is not None:
        defined = set()
        for domain, keys in english.items():
            defined.update('%s.%s' % (domain, key) for key in keys)
        for rel, number, key in used:
            if key not in defined and not is_ignored(rel, key):    # no file context needed here
                findings.append(Finding(ERROR, 'missing-key', rel, number,
                                        '%s is not defined in lang/en' % key))
        check_parity(catalog, findings)
    else:
        findings.append(Finding(ERROR, 'lang', 'lang/en', 0, 'directory not found'))

    dirty = {f.path for f in findings}
    clean = [path for path in scanned if path not in dirty]
    return findings, scanned, clean


def symbols(stream):
    """Status symbols, falling back to ASCII when the console cannot encode them."""
    try:
        '✓✗═'.encode(getattr(stream, 'encoding', None) or 'ascii')
        return '✓', '✗', '!', '═'
    except (UnicodeEncodeError, LookupError):
        return 'OK', 'x', '!', '='


def main(argv=None):
    parser = argparse.ArgumentParser(description='i18n localization audit for VoxelBooking.')
    parser.add_argument('--verbose', action='store_true', help='also list the clean files')
    parser.add_argument('--strict', action='store_true', help='treat warnings as errors')
    parser.add_argument('--include-api', action='store_true',
                        help='also report English messages inside Response::json() (as warnings)')
    parser.add_argument('--root', default=os.path.dirname(os.path.dirname(os.path.abspath(__file__))),
                        help='project root to audit (default: the checkout containing this script)')
    args = parser.parse_args(argv)

    for stream in (sys.stdout, sys.stderr):                    # never crash on a legacy console encoding
        if hasattr(stream, 'reconfigure'):
            stream.reconfigure(errors='replace')

    if not os.path.isdir(os.path.join(args.root, 'templates')):
        print('Not a VoxelBooking project root: %s' % args.root, file=sys.stderr)
        return 2

    findings, scanned, clean = audit(args.root, args.include_api)
    findings.sort(key=lambda f: (f.severity != ERROR, f.path, f.line, f.text))

    ok, bad, warn, rule = symbols(sys.stdout)
    if args.verbose:
        for path in clean:
            print('  %s %s' % (ok, path))
    for f in findings:
        location = '%s:%d' % (f.path, f.line) if f.line else f.path
        mark = bad if f.severity == ERROR else warn
        print('  %s [%s] %s: %s' % (mark, f.category, location, f.text))

    errors = [f for f in findings if f.severity == ERROR]
    warnings = [f for f in findings if f.severity == WARNING]
    print('\n' + rule * 50)
    print('Files scanned: %d   Errors: %d   Warnings: %d' % (len(scanned), len(errors), len(warnings)))
    failed = bool(errors) or (args.strict and bool(warnings))
    if failed:
        print('%s %d problem(s) need attention.' % (bad, len(errors) + (len(warnings) if args.strict else 0)))
    else:
        print('%s No hardcoded strings or missing translation keys found.' % ok)
    return 1 if failed else 0


if __name__ == '__main__':
    sys.exit(main())
