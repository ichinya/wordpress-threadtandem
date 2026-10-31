"""Offline deployment tests using disposable Git and SVN repositories."""
import importlib.util
import base64
import os
from pathlib import Path
import shutil
import subprocess
import tempfile
import unittest
from unittest.mock import patch

SPEC = importlib.util.spec_from_file_location("svn_deploy", Path(__file__).resolve().parents[1] / "tools/svn_deploy.py")
deploy = importlib.util.module_from_spec(SPEC)
SPEC.loader.exec_module(deploy)
SVN = os.environ.get("SVN_BINARY", "svn")
SVNADMIN = os.environ.get("SVNADMIN_BINARY", "svnadmin")


def run(*args):
    return subprocess.run(list(map(str, args)), capture_output=True, text=True, encoding="utf-8", errors="replace", check=True).stdout.strip()


def package(root, version, text="fixture"):
    root.mkdir(parents=True, exist_ok=True)
    (root / deploy.MAIN).write_text("<?php\n/**\n * Version: " + version + "\n */\ndefine('TREDA_VERSION', '" + version + "');\n", encoding="utf-8")
    (root / "readme.txt").write_text("=== Test fixture ===\nStable tag: " + version + "\n", encoding="utf-8")
    (root / "uninstall.php").write_text("<?php\n", encoding="utf-8")
    (root / "LICENSE").write_text("GPL-2.0-or-later\n", encoding="utf-8")
    (root / "includes").mkdir(exist_ok=True)
    (root / "includes/example.php").write_text("<?php // " + text + "\n", encoding="utf-8")
    return root


class DeploymentTests(unittest.TestCase):
    def setUp(self):
        self.temporary = tempfile.TemporaryDirectory(prefix="threadtandem-ci-test-")
        self.root = Path(self.temporary.name)
        self.repo = self.root / "svn"
        run(SVNADMIN, "create", self.repo)
        self.url = self.repo.as_uri()
        run(SVN, "mkdir", self.url + "/trunk", self.url + "/tags", self.url + "/assets", "-m", "Initialize fixture")
        self.git = self.root / "git"
        run("git", "init", "-b", "main", self.git)
        run("git", "-C", self.git, "config", "user.email", "ci@example.invalid")
        run("git", "-C", self.git, "config", "user.name", "CI fixture")
        self.first = self.commit("first")
        self.second = self.commit("second")
        self.third = self.commit("third")

    def tearDown(self):
        def writable(function, path, error):
            os.chmod(path, 0o700)
            function(path)
        shutil.rmtree(self.root, onerror=writable)
        self.temporary.cleanup()

    def commit(self, message):
        run("git", "-C", self.git, "commit", "--allow-empty", "-m", message)
        return run("git", "-C", self.git, "rev-parse", "HEAD")

    def publish(self, payload, mode, commit, tag=None):
        return deploy.deploy(payload, mode, commit, self.git, self.url, SVN, tag=tag)

    def cat(self, name):
        return run(SVN, "cat", self.url + "/" + name)

    def revision(self):
        return run(SVN, "info", "--show-item", "revision", self.url)

    def test_release_main_ordering_and_immutable_tags(self):
        initial = package(self.root / "initial", "9.8.7", "release 7")
        with self.assertRaisesRegex(ValueError, "initial Git version tag"):
            self.publish(initial, "main", self.first)
        self.publish(initial, "tag", self.first, "9.8.7")
        development = package(self.root / "development", "9.8.9", "newest main")
        (development / "includes/new.php").write_text("<?php // new file\n", encoding="utf-8")
        self.publish(development, "main", self.third)
        self.assertIn("9.8.7", self.cat("trunk/readme.txt"))
        self.assertIn("9.8.9", self.cat("trunk/" + deploy.MAIN))
        before = self.revision()
        self.assertIn("Skipped", self.publish(initial, "main", self.second))
        self.assertEqual(before, self.revision())
        release = package(self.root / "release", "9.8.8", "tagged earlier commit")
        self.publish(release, "tag", self.second, "9.8.8")
        self.assertEqual(int(before) + 1, int(self.revision()), "Tag and Stable tag must be one SVN commit")
        self.assertIn("9.8.8", self.cat("trunk/readme.txt"))
        self.assertIn("newest main", self.cat("trunk/includes/example.php"))
        self.assertIn("tagged earlier commit", self.cat("tags/9.8.8/includes/example.php"))
        self.assertNotIn("new.php", run(SVN, "list", self.url + "/tags/9.8.8/includes"))
        before = self.revision()
        self.assertIn("already contains", self.publish(release, "tag", self.second, "9.8.8"))
        self.assertEqual(before, self.revision())
        (release / "includes/example.php").write_text("<?php // different\n", encoding="utf-8")
        with self.assertRaisesRegex(ValueError, "immutable"):
            self.publish(release, "tag", self.second, "9.8.8")
        self.assertEqual(before, self.revision())
        old = package(self.root / "old", "9.8.6")
        self.publish(old, "tag", self.first, "9.8.6")
        self.assertIn("9.8.8", self.cat("trunk/readme.txt"))
        self.assertEqual("", run(SVN, "list", self.url + "/assets"))

    def test_main_deletes_files_and_is_idempotent(self):
        payload = package(self.root / "package", "9.8.7")
        self.publish(payload, "tag", self.first, "9.8.7")
        self.publish(payload, "main", self.second)
        before = self.revision()
        self.publish(payload, "main", self.second)
        self.assertEqual(before, self.revision())
        (payload / "includes/example.php").unlink()
        (payload / "includes/replacement.php").write_text("<?php\n", encoding="utf-8")
        self.publish(payload, "main", self.third)
        self.assertEqual("replacement.php", run(SVN, "list", self.url + "/trunk/includes"))
        self.assertIn("example.php", run(SVN, "list", self.url + "/tags/9.8.7/includes"))

    def test_divergent_main_is_rejected(self):
        payload = package(self.root / "package", "9.8.7")
        self.publish(payload, "tag", self.first, "9.8.7")
        self.publish(payload, "main", self.second)
        run("git", "-C", self.git, "checkout", "--detach", self.first)
        other = self.commit("different history")
        before = self.revision()
        with self.assertRaisesRegex(ValueError, "diverged"):
            self.publish(payload, "main", other)
        self.assertEqual(before, self.revision())

    def test_failure_before_commit_is_atomic(self):
        payload = package(self.root / "package", "9.8.7")
        before = self.revision()
        original = deploy.mirror
        def fail_on_tag(svn, target, content):
            original(svn, target, content)
            if target.parent.name == "tags":
                raise ValueError("simulated failure")
        with patch.object(deploy, "mirror", side_effect=fail_on_tag):
            with self.assertRaisesRegex(ValueError, "simulated failure"):
                self.publish(payload, "tag", self.first, "9.8.7")
        self.assertEqual(before, self.revision())
        self.assertEqual("", run(SVN, "list", self.url + "/tags"))

    def test_directory_assets_update_without_touching_release_or_other_images(self):
        payload = package(self.root / "package", "9.8.7")
        self.publish(payload, "tag", self.first, "9.8.7")
        images = self.root / "images"
        images.mkdir()
        png = base64.b64decode("iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mNk+A8AAQUBAScY42YAAAAASUVORK5CYII=")
        (images / "screenshot-1.png").write_bytes(png)
        original = self.cat("tags/9.8.7/readme.txt")
        def publish_images(commit):
            return deploy.deploy(payload, "main", commit, self.git, self.url, SVN, assets=images)
        before = self.revision()
        publish_images(self.second)
        self.assertEqual(int(before) + 1, int(self.revision()), "Images and trunk publish atomically")
        self.assertIn("screenshot-1.png", run(SVN, "list", self.url + "/assets"))
        self.assertEqual("image/png", run(SVN, "propget", "svn:mime-type", self.url + "/assets/screenshot-1.png"))
        self.assertEqual(original, self.cat("tags/9.8.7/readme.txt"))
        self.assertNotIn("screenshot", run(SVN, "list", self.url + "/trunk"))
        before = self.revision()
        publish_images(self.second)
        self.assertEqual(before, self.revision(), "Unchanged images do not create commits")
        (images / "screenshot-1.png").rename(images / "screenshot-2-ru.png")
        publish_images(self.third)
        self.assertEqual("screenshot-1.png\nscreenshot-2-ru.png", run(SVN, "list", self.url + "/assets"))
        (images / "screenshot-2-ru.png").write_bytes(png + b"stale")
        before = self.revision()
        self.assertIn("Skipped", publish_images(self.second))
        self.assertEqual(before, self.revision(), "Older main runs cannot overwrite current images")


class ValidationTests(unittest.TestCase):
    def setUp(self):
        self.temporary = tempfile.TemporaryDirectory()
        self.root = package(Path(self.temporary.name) / "package", "9.8.7")

    def tearDown(self):
        self.temporary.cleanup()

    def test_tag_version_agreement(self):
        deploy.validate(self.root, "9.8.7")
        with self.assertRaisesRegex(ValueError, "must match"):
            deploy.validate(self.root, "9.8.8")
        for invalid in ("v9.8.7", "9.8", "9.8.7-rc1", "09.8.7", "../9.8.7"):
            with self.assertRaises(ValueError):
                deploy.version_tuple(invalid)
        (self.root / "readme.txt").write_text("Stable tag: 9.8.6\n", encoding="utf-8")
        with self.assertRaisesRegex(ValueError, "must match"):
            deploy.validate(self.root, "9.8.7")

    def test_private_files_and_tokens_are_rejected(self):
        secret = self.root / ".info"
        secret.mkdir()
        (secret / "svn.txt").write_text("private", encoding="utf-8")
        with self.assertRaisesRegex(ValueError, "Hidden files"):
            deploy.validate(self.root)
        shutil.rmtree(secret)
        (self.root / "includes/example.php").write_text("<?php // 123456789:" + "A" * 35, encoding="utf-8")
        with self.assertRaisesRegex(ValueError, "credential"):
            deploy.validate(self.root)

    def test_password_uses_stdin_only(self):
        password = "test-password-never-log"
        client = deploy.SVN("svn", self.root / "config", "Ichi-nya", password)
        with patch.dict(os.environ, {"WPORG_SVN_PASSWORD": password}):
            with patch.object(deploy.subprocess, "run") as process:
                process.return_value = subprocess.CompletedProcess([], 0, "done", "")
                client.run("info", deploy.REPOSITORY)
                args, kwargs = process.call_args
                self.assertNotIn(password, args[0])
                self.assertIn("--password-from-stdin", args[0])
                self.assertEqual(password + "\n", kwargs["input"])
                self.assertNotIn("WPORG_SVN_PASSWORD", kwargs["env"])

    def test_wrong_remote_rejected_before_credentials_are_used(self):
        with self.assertRaisesRegex(ValueError, "Only the plugin"):
            deploy.deploy(self.root, "main", "a" * 40, self.root, "https://example.invalid/svn", password="secret")

    def test_directory_assets_reject_private_files_and_non_images(self):
        images = self.root / "directory-images"
        images.mkdir()
        (images / "svn.txt").write_text("private", encoding="utf-8")
        with self.assertRaisesRegex(ValueError, "allowlist"):
            deploy.directory_assets(images)
        (images / "svn.txt").unlink()
        (images / "screenshot-1.png").write_text("not an image", encoding="utf-8")
        with self.assertRaisesRegex(ValueError, "Expected a PNG"):
            deploy.directory_assets(images)


if __name__ == "__main__":
    unittest.main(verbosity=2)
