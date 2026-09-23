"""
Tests for scripts/audit-i18n.py.

Run from the project root:

    python3 -m unittest discover -s tests/scripts -v
"""

import contextlib
import importlib.util
import io
import os
import tempfile
import unittest

SCRIPT = os.path.join(os.path.dirname(__file__), '..', '..', 'scripts', 'audit-i18n.py')
spec = importlib.util.spec_from_file_location('audit_i18n', SCRIPT)
audit = importlib.util.module_from_spec(spec)
spec.loader.exec_module(audit)

BS = chr(92)  # a backslash, to keep the PHP snippets below readable


def categories(hits):
    return [category for category in (h[0] for h in hits)]


class HtmlDetectorTest(unittest.TestCase):
    def test_hardcoded_text_node_is_reported(self):
        self.assertEqual(audit.detect_html('<p>Save your changes</p>'), [('text', 'Save your changes')])

    def test_translated_output_is_not_reported(self):
        self.assertEqual(audit.detect_html("<p><?= __('admin.common.save') ?></p>"), [])

    def test_text_mixed_with_php_is_reported(self):
        hits = audit.detect_html("<title>Book – <?= htmlspecialchars($tenant['name']) ?></title>")
        self.assertEqual(categories(hits), ['text'])

    def test_hardcoded_attribute_is_reported(self):
        self.assertEqual(audit.detect_html('<input placeholder="Search bookings">'),
                         [('attribute', 'placeholder="Search bookings"')])
        self.assertEqual(categories(audit.detect_html('<button aria-label="Close">')), ['attribute'])
        self.assertEqual(categories(audit.detect_html('<form data-confirm-text="Delete">')), ['attribute'])

    def test_translated_or_technical_attributes_are_not_reported(self):
        for line in (
            """<input placeholder="<?= __('admin.x') ?>">""",
            '<input placeholder="smtp.example.com">',
            '<input placeholder="noreply@example.com">',
            '<input placeholder="0.00">',
            '<input placeholder="VoxelBooking">',
            '<a title="https://example.com/docs">',
        ):
            self.assertEqual(audit.detect_html(line), [], line)

    def test_text_between_php_tags_inside_a_tag_is_not_a_text_node(self):
        line = """<input class="<?= $c ?>" required value="<?= $v ?>">"""
        self.assertEqual(audit.detect_html(line), [])

    def test_meta_description_is_reported(self):
        hits = audit.detect_html('<meta name="description" content="Book an appointment">')
        self.assertEqual(categories(hits), ['attribute'])

    def test_escaped_entities_showing_code_are_not_text(self):
        self.assertEqual(audit.detect_html('<code>&lt;script src="x"&gt;</code>'), [])


class PhpDetectorTest(unittest.TestCase):
    def test_english_only_date_tokens_are_reported(self):
        hits = audit.detect_php("<?= date('M j, Y', strtotime($d)) ?>")
        self.assertEqual(categories(hits), ['date'])
        self.assertEqual(categories(audit.detect_php("$dt->format('F')")), ['date'])
        self.assertEqual(categories(audit.detect_php("date('D, d M Y')")), ['date'])

    def test_numeric_and_escaped_date_formats_are_fine(self):
        for line in ("date('Y-m-d')", "date('H:i')", "$dt->format('d/m/Y H:i')",
                     "$dt->format('j " + BS + 'd' + BS + "e Y')", "number_format($n, 2)"):
            self.assertEqual(audit.detect_php(line), [], line)

    def test_label_entries(self):
        self.assertEqual(categories(audit.detect_php("'label' => 'Booking Confirmation',")), ['label'])
        self.assertEqual(audit.detect_php("'label' => __('admin.x.label'),"), [])
        self.assertEqual(audit.detect_php("'title' => 'settings',"), [])


class ServerMessageDetectorTest(unittest.TestCase):
    def test_hardcoded_messages_are_reported(self):
        for line in (
            "FormState::toast('error', 'Could not save the settings.');",
            "$errors[] = 'Application name is required.';",
            "return ['success' => false, 'error' => 'Invalid email or password.'];",
            "return Response::html('<h1>404 Not Found</h1>', 404);",
        ):
            self.assertEqual(categories(audit.detect_server_message(line)), ['message'], line)

    def test_translated_messages_and_codes_are_fine(self):
        for line in (
            "FormState::toast('error', __('admin.common.error_generic'));",
            "return ['error' => 'not_found'];",
            "return ['error' => 'Forbidden'];",
            "$errors[] = __('admin.settings.error_app_name_required');",
        ):
            self.assertEqual(audit.detect_server_message(line), [], line)


class JsDetectorTest(unittest.TestCase):
    def test_hardcoded_strings_are_reported(self):
        self.assertEqual(categories(audit.detect_js("btn.setAttribute('aria-label', 'Close booking');")), ['string'])
        self.assertEqual(categories(audit.detect_js('close.setAttribute("aria-label","Close booking");')), ['string'])
        self.assertEqual(categories(audit.detect_js("el.innerHTML = '<p>Loading your bookings</p>';")), ['text'])
        self.assertEqual(categories(audit.detect_js("const label = form.get('x') || 'Confirm this action';")), ['string'])

    def test_translated_developer_and_technical_strings_are_fine(self):
        for line in (
            "el.textContent = t('steps.service_title');",
            "console.error('Failed to fetch availability:', e);",
            "if (e.key === 'Escape') close();",
            "if (['ArrowDown', 'ArrowUp'].includes(e.key)) go();",
            "headers: { 'Content-Type': 'application/json' },",
            "const html = '<span>' + (label.replace(/</g, '&lt;')) + '</span>';",
        ):
            self.assertEqual(audit.detect_js(line), [], line)


class FileScannerTest(unittest.TestCase):
    def scan(self, source, scanner=audit.scan_template, **kwargs):
        return [(n, c) for n, c, _t in scanner(source.split('\n'), **kwargs)]

    def test_style_blocks_and_comments_are_skipped(self):
        source = '\n'.join([
            '<style>', '.a > Hello World < .b { }', '</style>',
            '<!-- <p>Some comment text</p> -->', '// <p>Another comment</p>', '<p>Real text</p>',
        ])
        self.assertEqual(self.scan(source), [(6, 'text')])

    def test_script_blocks_use_the_js_rules(self):
        source = '\n'.join([
            '<script>', "  el.textContent = 'Copied to clipboard!';", '</script>',
            "<script>window.X = 'Shown to users';</script>",
        ])
        self.assertEqual(self.scan(source), [(2, 'string'), (4, 'string')])

    def test_multiline_php_block_only_gets_php_rules(self):
        source = '\n'.join([
            '<?php',
            "if ($a > 5 && $b < 3 && $name == 'Some Name') {",
            "    $x = ['label' => 'Hardcoded Label'];",
            '}',
            '?>',
            '<p>Text after the block</p>',
        ])
        self.assertEqual(self.scan(source), [(3, 'label'), (6, 'text')])

    def test_ignore_marker_silences_a_line(self):
        self.assertEqual(self.scan('<p>Some text</p> <!-- i18n-ignore -->'), [])

    def test_app_markup_rules_need_a_tag(self):
        self.assertEqual(self.scan("if ($a > 5 && $name < 'Some Name') { }", app=True), [])
        self.assertEqual(self.scan("$html = '<td>Some text</td>';", app=True), [(1, 'text')])

    def test_heredoc_js_uses_the_js_rules(self):
        source = '\n'.join(['return <<<JS', "btn.setAttribute('aria-label', 'Close booking');", 'JS;'])
        self.assertEqual(self.scan(source, app=True), [(2, 'string')])


class JsonMessageTest(unittest.TestCase):
    def hits(self, source, include_api=False):
        return [(n, c) for n, c, _t in audit.scan_php_source(source.split('\n'), include_api)]

    def test_json_messages_are_skipped_unless_requested(self):
        source = "return Response::json(['error' => 'x', 'message' => 'Owner access required.'], 403);"
        self.assertEqual(self.hits(source), [])
        self.assertEqual(self.hits(source, include_api=True), [(1, 'api-message')])

    def test_multiline_json_call(self):
        source = '\n'.join(["return Response::json([", "    'message' => 'Access denied.',", '], 403);'])
        self.assertEqual(self.hits(source), [])

    def test_a_finished_json_call_does_not_hide_later_messages(self):
        source = '\n'.join([
            "return Response::json(['message' => 'Not found'], 404);",
            "return Response::html('<h1>404 Not Found</h1>', 404);",
        ])
        self.assertEqual(self.hits(source), [(2, 'message')])


class LangParserTest(unittest.TestCase):
    def test_nested_arrays_and_key_types(self):
        source = ("<?php\ndeclare(strict_types=1);\n/* doc */\nreturn [\n"
                  "    'a' => 'One', // comment\n    'group' => [1 => 'Jan', 2 => 'Feb', 'x' => \"Dbl\"],\n"
                  "    'list' => ['p', 'q'],\n];\n")
        flat = audit.flatten(audit.parse_lang_file(source))
        self.assertEqual(flat, {'a': 'One', 'group.1': 'Jan', 'group.2': 'Feb', 'group.x': 'Dbl',
                                'list.0': 'p', 'list.1': 'q'})

    def test_escapes_and_concatenation(self):
        source = "<?php return ['a' => 'It" + BS + "'s', 'b' => 'x' . 'y', 'c' => \"Say " + BS + '"hi' + BS + "\"\"];"
        data = audit.parse_lang_file(source)
        self.assertEqual(data, {'a': "It's", 'b': 'xy', 'c': 'Say "hi"'})

    def test_unparsable_file_raises(self):
        with self.assertRaises(audit.LangParseError):
            audit.parse_lang_file("<?php return ['a' => $variable];")


class IgnoreTest(unittest.TestCase):
    def test_context_pattern_must_match_the_file(self):
        original = audit.IGNORE
        audit.IGNORE = [(r'^a\.js$', r'Confirm', 'reason', r'PAYLOAD')]
        try:
            self.assertFalse(audit.is_ignored('a.js', 'Confirm', 'no marker here'))
            self.assertTrue(audit.is_ignored('a.js', 'Confirm', 'uses PAYLOAD'))
            self.assertFalse(audit.is_ignored('b.js', 'Confirm', 'uses PAYLOAD'))
        finally:
            audit.IGNORE = original


class ProjectAuditTest(unittest.TestCase):
    def make_project(self, files):
        tmp = tempfile.TemporaryDirectory()
        self.addCleanup(tmp.cleanup)
        for path, content in files.items():
            full = os.path.join(tmp.name, path)
            os.makedirs(os.path.dirname(full), exist_ok=True)
            with open(full, 'w', encoding='utf-8') as fh:
                fh.write(content)
        return tmp.name

    def run_main(self, root, *args):
        out = io.StringIO()
        with contextlib.redirect_stdout(out), contextlib.redirect_stderr(io.StringIO()):
            code = audit.main(['--root', root] + list(args))
        return code, out.getvalue()

    EN = "<?php return ['common' => ['save' => 'Save', 'hello' => 'Hi :name']];"

    def test_clean_project(self):
        root = self.make_project({
            'templates/a.php': "<p><?= __('admin.common.save') ?></p>\n",
            'lang/en/admin.php': "<?php return ['common' => ['save' => 'Save']];",
        })
        code, output = self.run_main(root)
        self.assertEqual(code, 0, output)

    def test_hardcoded_text_and_missing_key_fail(self):
        root = self.make_project({
            'templates/a.php': "<p>Hello there</p>\n<p><?= __('admin.common.nope') ?></p>\n",
            'lang/en/admin.php': "<?php return ['common' => ['save' => 'Save']];",
        })
        code, output = self.run_main(root)
        self.assertEqual(code, 1)
        self.assertIn('[text] templates/a.php:1', output)
        self.assertIn('[missing-key] templates/a.php:2: admin.common.nope', output)

    def test_dynamic_keys_and_js_booking_keys(self):
        root = self.make_project({
            'templates/a.php': "<?= __('admin.status_' . $s) ?>\n",
            'resources/js/booking/app.js': "x = t('steps.title'); y = t('steps.gone');\n",
            'lang/en/admin.php': "<?php return ['x' => 'y'];",
            'lang/en/booking.php': "<?php return ['steps' => ['title' => 'T']];",
        })
        code, output = self.run_main(root)
        self.assertEqual(code, 1)
        self.assertIn('booking.steps.gone', output)
        self.assertNotIn('booking.steps.title', output)
        self.assertNotIn('status_', output)

    def test_locale_parity_is_a_warning_unless_strict(self):
        root = self.make_project({
            'templates/a.php': "<p><?= __('admin.hi') ?></p>\n",
            'lang/en/admin.php': "<?php return ['hi' => 'Hi :name', 'bye' => 'Bye'];",
            'lang/fr/admin.php': "<?php return ['hi' => 'Salut', 'extra' => 'x'];",
        })
        code, output = self.run_main(root)
        self.assertEqual(code, 0, output)
        self.assertIn('missing key bye', output)
        self.assertIn('key not in lang/en: extra', output)
        self.assertIn('placeholders differ for hi', output)
        code, _ = self.run_main(root, '--strict')
        self.assertEqual(code, 1)

    def test_not_a_project_root(self):
        code, _ = self.run_main(tempfile.gettempdir() + os.sep + 'no-such-project-dir')
        self.assertEqual(code, 2)


if __name__ == '__main__':
    unittest.main()
