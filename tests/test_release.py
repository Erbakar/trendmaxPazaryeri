"""Privacy boundaries of the web release package."""
import importlib.util
from pathlib import Path
import unittest

spec = importlib.util.spec_from_file_location('release', Path(__file__).resolve().parents[1] / 'tools/build-release.py')
release = importlib.util.module_from_spec(spec)
spec.loader.exec_module(release)


class ReleaseBoundaryTest(unittest.TestCase):
    def test_customer_and_operator_files_are_excluded(self):
        for name in ['.env', '.git/config', 'README.local.md', 'installer_done.lock',
                     'storage/local/database.sql', 'storage/backups/private.zip',
                     'storage/import_xml/customer.xml', 'storage/publish_jobs/job.json',
                     'public/uploads/products/private.jpg', 'tests/fixtures/sample.xml',
                     'tools/smoke-local.py', 'electron/main.js', 'source.zip', '../.env']:
            with self.subTest(name=name):
                self.assertFalse(release.include(name))

    def test_sources_schema_guards_and_intended_download_are_included(self):
        for name in ['app/routes.php', 'config/app.php', 'cron/sync_xml_profiles.php',
                     'migrations/001_add_performance_indexes.sql',
                     'database/migrations/phase14/058_product_category_overrides.sql',
                     'views/xml_profiles/form.php', 'public/assets/app.css', '.htaccess',
                     '.env.example', 'composer.lock', 'storage/backups/.gitkeep',
                     'storage/publish_jobs/.htaccess', release.PUBLIC_PACKAGE,
                     'tools/store-archive.php']:
            with self.subTest(name=name):
                self.assertTrue(release.include(name))


if __name__ == '__main__':
    unittest.main()
