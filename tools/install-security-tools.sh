#!/usr/bin/env bash

set -Eeuo pipefail
umask 077

die() {
    printf 'security-tool installer: %s\n' "$1" >&2
    exit 1
}

require_command() {
    command -v "$1" >/dev/null 2>&1 || die "required command is unavailable: $1"
}

sha256_file() {
    sha256sum -- "$1" | awk '{print tolower($1)}'
}

script_dir="$(cd -- "$(dirname -- "${BASH_SOURCE[0]}")" && pwd -P)"
repository_root="$(cd -- "$script_dir/.." && pwd -P)"
manifest_path="$repository_root/config/tool-manifest.json"
binary_directory="$repository_root/tools/bin"

[[ -f "$manifest_path" ]] || die 'the pinned tool manifest is missing'

for required_command in awk curl install mktemp python3 sha256sum tar; do
    require_command "$required_command"
done

declare -A approved_repositories=(
    [Gitleaks]='gitleaks/gitleaks'
    [OSV-Scanner]='google/osv-scanner'
    [Syft]='anchore/syft'
    [Grype]='anchore/grype'
)
declare -A executable_names=(
    [Gitleaks]='gitleaks'
    [OSV-Scanner]='osv-scanner'
    [Syft]='syft'
    [Grype]='grype'
)
declare -A packaging=(
    [Gitleaks]='tar.gz'
    [OSV-Scanner]='direct'
    [Syft]='tar.gz'
    [Grype]='tar.gz'
)
declare -A version_arguments=(
    [Gitleaks]='version'
    [OSV-Scanner]='--version'
    [Syft]='version'
    [Grype]='version'
)

mapfile -t tool_rows < <(
    python3 - "$manifest_path" <<'PY'
import json
import sys

with open(sys.argv[1], encoding="utf-8") as handle:
    manifest = json.load(handle)

tools = manifest.get("tools")
if not isinstance(tools, list):
    raise SystemExit("manifest tools must be a list")

for tool in tools:
    if not isinstance(tool, dict):
        raise SystemExit("every manifest tool must be an object")
    fields = (
        tool.get("name"),
        tool.get("version"),
        tool.get("source"),
        tool.get("linux_artifact"),
        tool.get("linux_artifact_sha256"),
        tool.get("linux_executable_sha256", ""),
    )
    if not all(isinstance(value, str) for value in fields):
        raise SystemExit("manifest Linux tool fields must be strings")
    if any("\t" in value or "\n" in value or "\r" in value for value in fields):
        raise SystemExit("manifest tool fields must be single-line values")
    print("\t".join(fields))
PY
)

[[ "${#tool_rows[@]}" -eq "${#approved_repositories[@]}" ]] || die "expected exactly ${#approved_repositories[@]} pinned Linux tools; found ${#tool_rows[@]}"

temp_root=''
work_directory=''
incoming_paths=()
cleanup() {
    local exit_status=$?
    local incoming_path
    local resolved_work_directory

    for incoming_path in "${incoming_paths[@]}"; do
        if [[ -e "$incoming_path" ]]; then
            [[ "$(dirname -- "$incoming_path")" == "$binary_directory" ]] || {
                printf 'security-tool installer: refusing to clean unexpected incoming path: %s\n' "$incoming_path" >&2
                exit 1
            }
            rm -f -- "$incoming_path"
        fi
    done

    if [[ -n "$work_directory" && -d "$work_directory" ]]; then
        resolved_work_directory="$(cd -- "$work_directory" && pwd -P)"
        [[ "$resolved_work_directory" == "$work_directory" ]] || {
            printf 'security-tool installer: refusing to clean a work directory whose resolved path changed\n' >&2
            exit 1
        }
        [[ "$resolved_work_directory" == "$temp_root"/wtfcode-security-tools.* ]] || {
            printf 'security-tool installer: refusing to clean outside the resolved temp directory\n' >&2
            exit 1
        }
        rm -rf -- "$resolved_work_directory"
    fi

    exit "$exit_status"
}
trap cleanup EXIT
trap 'exit 130' INT
trap 'exit 143' TERM

temp_root="${TMPDIR:-/tmp}"
temp_root="$(cd -- "$temp_root" && pwd -P)"
work_directory="$(mktemp -d -- "$temp_root/wtfcode-security-tools.XXXXXXXX")"
[[ "$work_directory" == "$temp_root"/wtfcode-security-tools.* ]] || die 'refusing to use a work directory outside the resolved temp directory'

declare -A seen_names=()
declare -A pinned_versions=()
declare -A staged_executables=()
declare -A staged_hashes=()
ordered_names=()

for row in "${tool_rows[@]}"; do
    IFS=$'\t' read -r name version source linux_artifact linux_artifact_sha256 linux_executable_sha256 <<< "$row"

    [[ -n "${approved_repositories[$name]+approved}" ]] || die "unapproved tool in manifest: $name"
    [[ -z "${seen_names[$name]+seen}" ]] || die "duplicate tool in manifest: $name"
    seen_names[$name]=1
    ordered_names+=("$name")

    [[ "$version" =~ ^[0-9]+\.[0-9]+\.[0-9]+([-+][0-9A-Za-z.-]+)?$ ]] || die "$name has an invalid pinned version"
    [[ "$linux_artifact" =~ ^[A-Za-z0-9._-]+$ ]] || die "$name has an unsafe Linux artifact name"
    [[ "$linux_artifact" != '.' && "$linux_artifact" != '..' ]] || die "$name has an unsafe Linux artifact name"
    [[ "$linux_artifact_sha256" =~ ^[a-fA-F0-9]{64}$ ]] || die "$name has an invalid Linux artifact SHA-256"
    if [[ -n "$linux_executable_sha256" ]]; then
        [[ "$linux_executable_sha256" =~ ^[a-fA-F0-9]{64}$ ]] || die "$name has an invalid Linux executable SHA-256"
    fi

    repository="${approved_repositories[$name]}"
    expected_source="https://github.com/$repository/releases/tag/v$version"
    [[ "$source" == "$expected_source" ]] || die "$name source is not its approved official repository and pinned release tag"

    tag="v$version"
    asset_url="https://github.com/$repository/releases/download/$tag/$linux_artifact"
    artifact_path="$work_directory/${name//-/}_$linux_artifact"
    executable_name="${executable_names[$name]}"
    staged_path="$work_directory/$executable_name.staged"

    printf 'Downloading %s %s from %s\n' "$name" "$version" "$asset_url"
    curl \
        --fail \
        --location \
        --silent \
        --show-error \
        --proto '=https' \
        --proto-redir '=https' \
        --tlsv1.2 \
        --retry 3 \
        --retry-all-errors \
        --output "$artifact_path" \
        "$asset_url"

    downloaded_hash="$(sha256_file "$artifact_path")"
    [[ "$downloaded_hash" == "${linux_artifact_sha256,,}" ]] || die "$name Linux artifact SHA-256 mismatch: expected ${linux_artifact_sha256,,}; received $downloaded_hash"

    if [[ "${packaging[$name]}" == 'tar.gz' ]]; then
        [[ "$linux_artifact" == *.tar.gz ]] || die "$name must use its pinned tar.gz artifact"
        mapfile -t matching_members < <(
            tar -tzf "$artifact_path" | awk -v expected="$executable_name" '
                {
                    entry = $0
                    sub(/^\.\//, "", entry)
                    count = split(entry, parts, "/")
                    if (parts[count] == expected && entry !~ /\/$/) print $0
                }
            '
        )
        [[ "${#matching_members[@]}" -eq 1 ]] || die "$name archive must contain exactly one $executable_name entry; found ${#matching_members[@]}"
        tar -xOzf "$artifact_path" -- "${matching_members[0]}" > "$staged_path"
    elif [[ "${packaging[$name]}" == 'direct' ]]; then
        [[ "$linux_artifact" != *.tar.gz ]] || die "$name must use its pinned direct executable artifact"
        cp -- "$artifact_path" "$staged_path"
    else
        die "$name has an unsupported packaging type"
    fi

    [[ -s "$staged_path" ]] || die "$name produced an empty staged executable"
    chmod 0755 "$staged_path"
    staged_hash="$(sha256_file "$staged_path")"
    if [[ -n "$linux_executable_sha256" ]]; then
        [[ "$staged_hash" == "${linux_executable_sha256,,}" ]] || die "$name executable SHA-256 mismatch: expected ${linux_executable_sha256,,}; received $staged_hash"
    fi

    pinned_versions[$name]="$version"
    staged_executables[$name]="$staged_path"
    staged_hashes[$name]="$staged_hash"
done

for expected_name in "${!approved_repositories[@]}"; do
    [[ -n "${seen_names[$expected_name]+seen}" ]] || die "manifest is missing $expected_name"
done

mkdir -p -- "$binary_directory"
binary_directory="$(cd -- "$binary_directory" && pwd -P)"

for name in "${ordered_names[@]}"; do
    executable_name="${executable_names[$name]}"
    destination="$binary_directory/$executable_name"
    incoming="$binary_directory/.$executable_name.incoming.$$.$RANDOM"
    incoming_paths+=("$incoming")

    install -m 0755 -- "${staged_executables[$name]}" "$incoming"
    incoming_hash="$(sha256_file "$incoming")"
    [[ "$incoming_hash" == "${staged_hashes[$name]}" ]] || die "$name incoming executable failed its integrity check"
    mv -f -- "$incoming" "$destination"

    final_hash="$(sha256_file "$destination")"
    [[ "$final_hash" == "${staged_hashes[$name]}" ]] || die "$name installed executable failed its final SHA-256 check"

    version_output="$("$destination" "${version_arguments[$name]}" 2>&1)" || die "$name version check failed"
    [[ "$version_output" == *"${pinned_versions[$name]}"* ]] || die "$name version output did not include ${pinned_versions[$name]}"

    printf '%s\t%s\t%s\t%s\n' "$name" "${pinned_versions[$name]}" "$final_hash" "$destination"
done

printf 'Installed and verified %d pinned Linux security tools in %s\n' "${#ordered_names[@]}" "$binary_directory"
