#!/usr/bin/env python3
"""Publish a built plugin to WordPress.org; local file:// repositories are for tests."""
import argparse
from contextlib import contextmanager
import hashlib
import os
from pathlib import Path
import re
import subprocess
import sys
import tempfile
import time
import xml.etree.ElementTree as ET

SLUG = "threadtandem-sync-for-telegram"
MAIN = SLUG + ".php"
REPOSITORY = "https://plugins.svn.wordpress.org/" + SLUG
COMMIT_PROPERTY = "threadtandem:git-commit"
VERSION = re.compile(r"(?:0|[1-9]\d*)\.(?:0|[1-9]\d*)\.(?:0|[1-9]\d*)\Z")
STABLE = re.compile(r"(?m)^(Stable tag:\s*)([^\s]+)")


@contextmanager
def working_directory():
    temporary = tempfile.TemporaryDirectory(prefix="threadtandem-svn-")
    try:
        yield Path(temporary.name)
    finally:
        # Windows may briefly retain SQLite/antivirus handles after svn exits.
        for attempt in range(5):
            try:
                temporary.cleanup()
                break
            except OSError:
                if os.name != "nt" or attempt == 4:
                    raise
                time.sleep(0.2)


def version_tuple(value):
    if not VERSION.fullmatch(value):
        raise ValueError("Expected a release version X.Y.Z: " + value)
    return tuple(map(int, value.split(".")))


def files(root):
    result = {}
    for path in root.rglob("*"):
        relative = path.relative_to(root)
        if path.is_symlink():
            raise ValueError("Symlinks are not permitted: " + str(relative))
        if path.is_file():
            result[relative.as_posix()] = path.read_bytes()
    return result


def validate(package, tag=None):
    if package.is_symlink():
        raise ValueError("Package root must not be a symlink")
    payload = files(package)
    required = {MAIN, "readme.txt", "uninstall.php", "LICENSE"}
    if not required.issubset(payload) or not any(p.startswith("includes/") for p in payload):
        raise ValueError("Incomplete plugin package")
    allowed = {"includes": {".php"}, "assets": {".js", ".css", ".svg", ".png", ".jpg", ".jpeg", ".gif", ".webp"},
               "languages": {".po", ".mo", ".json", ".php"}}
    for name, content in payload.items():
        path = Path(name)
        if any(part.startswith(".") for part in path.parts):
            raise ValueError("Hidden files are not permitted: " + name)
        if name not in required and (len(path.parts) < 2 or path.parts[0] not in allowed
                                     or path.suffix not in allowed[path.parts[0]]):
            raise ValueError("File outside release allowlist: " + name)
        if re.search(rb"(?<![A-Za-z0-9])\d{6,14}:[A-Za-z0-9_-]{30,}(?![A-Za-z0-9])", content):
            raise ValueError("Possible embedded bot credential: " + name)
    main = payload[MAIN].decode("utf-8-sig")
    header = re.search(r"(?m)^\s*\* Version:\s*(\S+)", main)
    constant = re.search(r"define\(\s*['\"]TREDA_VERSION['\"]\s*,\s*['\"]([^'\"]+)", main)
    if not header or not constant or header[1] != constant[1]:
        raise ValueError("Plugin header and TREDA_VERSION must agree")
    version = header[1]
    version_tuple(version)
    stable = STABLE.search(payload["readme.txt"].decode("utf-8-sig"))
    if not stable:
        raise ValueError("Missing Stable tag")
    version_tuple(stable[2])
    if tag is not None and (version != tag or stable[2] != tag):
        raise ValueError("Git tag, plugin version and readme Stable tag must match")
    return payload


class SVN:
    def __init__(self, binary, config, username=None, password=None):
        self.binary, self.config = binary, config
        self.username, self.password = username, password

    def run(self, *args):
        command = [self.binary, *map(str, args), "--non-interactive", "--no-auth-cache", "--config-dir", str(self.config)]
        if self.password:
            command += ["--username", self.username, "--password-from-stdin"]
        env = {k: v for k, v in os.environ.items() if k != "WPORG_SVN_PASSWORD"}
        result = subprocess.run(command, input=self.password + "\n" if self.password else None,
                                capture_output=True, text=True, encoding="utf-8", errors="replace", env=env)
        if result.returncode:
            error = result.stderr
            if self.password:
                error = error.replace(self.password, "[REDACTED]")
            raise RuntimeError("SVN command failed: " + error.strip())
        return result.stdout

    def property(self, path, name):
        document = ET.fromstring(self.run("proplist", "--xml", "--verbose", path))
        return next((p.text for p in document.iter("property") if p.get("name") == name), None)


def mirror(svn, target, payload):
    """Only mutate a fresh, private SVN working copy, never the source checkout."""
    directories = {parent.as_posix() for name in payload for parent in Path(name).parents if parent != Path(".")}
    for path in sorted(target.rglob("*"), key=lambda p: len(p.parts)):
        name = path.relative_to(target).as_posix()
        if ".svn" in path.relative_to(target).parts or not path.exists():
            continue
        if path.is_symlink():
            raise ValueError("Refusing a symlink in SVN working copy")
        keep = name in directories if path.is_dir() else name in payload
        if not keep:
            svn.run("delete", "--force", path)
    for name, content in payload.items():
        path = target / name
        path.parent.mkdir(parents=True, exist_ok=True)
        if not path.exists() or path.read_bytes() != content:
            path.write_bytes(content)
    svn.run("add", "--force", "--no-ignore", target)


def stable_version(payload):
    match = STABLE.search(payload.get("readme.txt", b"").decode("utf-8-sig"))
    return match[2] if match else None


def set_stable(payload, version):
    result = dict(payload)
    result["readme.txt"] = STABLE.sub(lambda m: m[1] + version, payload["readme.txt"].decode("utf-8-sig")).encode("utf-8")
    return result


def is_ancestor(repo, older, newer):
    for value in (older, newer):
        if not re.fullmatch(r"[0-9a-f]{40}", value):
            raise ValueError("Invalid recorded Git commit")
    result = subprocess.run(["git", "-C", str(repo), "merge-base", "--is-ancestor", older, newer], capture_output=True)
    if result.returncode not in (0, 1):
        raise ValueError("Cannot verify Git ancestry; fetch full history before deploying")
    return result.returncode == 0


def deploy(package, mode, commit, git_repo, repository=REPOSITORY, svn_binary="svn", username="Ichi-nya", password=None, tag=None):
    payload = validate(package, tag if mode == "tag" else None)
    if mode not in ("main", "tag") or (mode == "tag" and not tag):
        raise ValueError("Invalid deployment mode")
    if not re.fullmatch(r"[0-9a-f]{40}", commit):
        raise ValueError("A full Git commit SHA is required")
    if repository.rstrip("/") != REPOSITORY and not repository.startswith("file://"):
        raise ValueError("Only the plugin's WordPress.org repository or a local test repository is allowed")
    if not repository.startswith("file://") and not password:
        raise ValueError("Set the GitHub Actions repository secret WPORG_SVN_PASSWORD")
    with working_directory() as temporary:
        svn = SVN(svn_binary, temporary / "config", username, password)
        wc = temporary / "working-copy"
        svn.run("checkout", "--depth", "empty", repository, wc)
        svn.run("update", "--set-depth", "infinity", wc / "trunk")
        svn.run("update", "--set-depth", "immediates", wc / "tags")
        trunk, tags = wc / "trunk", wc / "tags"
        if not trunk.is_dir() or not tags.is_dir():
            raise ValueError("SVN must contain trunk/ and tags/ directories")
        current = files(trunk)
        stable = stable_version(current)
        if current and (not stable or not VERSION.fullmatch(stable) or not (tags / stable).is_dir()):
            raise ValueError("SVN trunk must point to an existing versioned release before development deployment")
        if mode == "main":
            if not current:
                raise ValueError("Publish an initial Git version tag before deploying development commits")
            recorded = svn.property(trunk, COMMIT_PROPERTY)
            if recorded and recorded != commit:
                if is_ancestor(git_repo, commit, recorded):
                    return "Skipped superseded main commit " + commit
                if not is_ancestor(git_repo, recorded, commit):
                    raise ValueError("Main history diverged; reconcile SVN manually before deploying")
            mirror(svn, trunk, set_stable(payload, stable))
            svn.run("propset", COMMIT_PROPERTY, commit, trunk)
        else:
            destination = tags / tag
            if destination.exists():
                svn.run("update", "--set-depth", "infinity", destination)
                if files(destination) != payload:
                    raise ValueError("SVN tag already exists with different files; tags are immutable")
                return "SVN tag " + tag + " already contains this package; no changes"
            if not current:
                mirror(svn, trunk, payload)
                svn.run("propset", COMMIT_PROPERTY, commit, trunk)
            svn.run("copy", trunk, destination)
            mirror(svn, destination, payload)
            svn.run("propset", COMMIT_PROPERTY, commit, destination)
            if stable is None or version_tuple(tag) > version_tuple(stable):
                # Preserve newer main code; change only the released version pointer.
                mirror(svn, trunk, set_stable(files(trunk), tag))
        status = ET.fromstring(svn.run("status", "--xml", wc))
        if not list(status.iter("entry")):
            return "SVN is already up to date"
        svn.run("commit", wc, "--message", "GitHub " + mode + " " + (tag or commit) + " (" + commit + ")")
        return "Published " + mode + " " + (tag or commit)


def main():
    parser = argparse.ArgumentParser(description=__doc__)
    parser.add_argument("command", choices=("validate", "deploy"))
    parser.add_argument("--package", type=Path, required=True)
    parser.add_argument("--ref", required=True)
    parser.add_argument("--commit", required=True)
    args = parser.parse_args()
    tag = args.ref.removeprefix("refs/tags/").removeprefix("v") if args.ref.startswith("refs/tags/") else None
    if tag is not None:
        version_tuple(tag)
    elif args.ref != "refs/heads/main" and args.command == "deploy":
        raise ValueError("Only main and version tags may deploy")
    payload = validate(args.package, tag)
    digest = hashlib.sha256(b"".join(name.encode() + b"\0" + hashlib.sha256(data).digest()
                                    for name, data in sorted(payload.items()))).hexdigest()
    print("Validated " + str(len(payload)) + " package files; tree SHA256 " + digest)
    if args.command == "deploy":
        password = os.environ.pop("WPORG_SVN_PASSWORD", None)
        print(deploy(args.package, "tag" if tag else "main", args.commit, Path.cwd(), password=password, tag=tag))


if __name__ == "__main__":
    try:
        main()
    except (ValueError, RuntimeError) as error:
        print("Error: " + str(error), file=sys.stderr)
        sys.exit(1)
