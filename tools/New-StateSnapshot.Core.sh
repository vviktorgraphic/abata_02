#!/usr/bin/env bash

set -euo pipefail
umask 077

fail() {
    printf 'State snapshot failed: %s\n' "$1" >&2
    exit 1
}

release_root=''
snapshot_root=''
database_backup=''
database_checksum=''
snapshot_id=''
local_test_failure=''

while (($# > 0)); do
    case "$1" in
        --release-root) release_root="${2:-}"; shift 2 ;;
        --snapshot-root) snapshot_root="${2:-}"; shift 2 ;;
        --database-backup) database_backup="${2:-}"; shift 2 ;;
        --database-checksum) database_checksum="${2:-}"; shift 2 ;;
        --snapshot-id) snapshot_id="${2:-}"; shift 2 ;;
        --local-test-failure) local_test_failure="${2:-}"; shift 2 ;;
        *) fail 'unsupported argument' ;;
    esac
done

for command in realpath sha256sum tar stat find sort cp chmod mv; do
    command -v "$command" >/dev/null 2>&1 || fail "required command is unavailable: $command"
done

[[ "$release_root" == /* ]] || fail 'release root must be an absolute path'
[[ "$snapshot_root" == /* ]] || fail 'snapshot root must be an absolute path'
[[ "$database_backup" == /* ]] || fail 'database backup must be an absolute path'
[[ "$database_checksum" == /* ]] || fail 'database checksum must be an absolute path'
[[ -d "$release_root" ]] || fail 'release root is missing'
[[ -f "$release_root/.env" && -s "$release_root/.env" ]] || fail 'release environment file is missing or empty'
[[ -d "$snapshot_root" ]] || fail 'snapshot root is missing'
[[ -f "$database_backup" && -s "$database_backup" ]] || fail 'database backup is missing or empty'
[[ -f "$database_checksum" && -s "$database_checksum" ]] || fail 'database checksum is missing or empty'

release_root=$(realpath -e "$release_root")
snapshot_root=$(realpath -e "$snapshot_root")
database_backup=$(realpath -e "$database_backup")
database_checksum=$(realpath -e "$database_checksum")
environment_file=$(realpath -e "$release_root/.env")

case "$snapshot_root/" in
    "$release_root/"*) fail 'snapshot root must be outside the selected release root' ;;
esac

if [[ -z "$snapshot_id" ]]; then
    snapshot_id="$(date +%Y%m%d_%H%M%S)_$(basename "$release_root")"
fi
[[ "$snapshot_id" =~ ^[A-Za-z0-9][A-Za-z0-9._-]{0,100}$ ]] || fail 'snapshot id is invalid'

if [[ -n "$local_test_failure" ]]; then
    [[ "$snapshot_root" == /tmp/* ]] || fail 'failure simulation is restricted to /tmp'
    [[ "$local_test_failure" == archive || "$local_test_failure" == manifest ]] || fail 'unsupported failure simulation'
fi

chmod 700 "$snapshot_root" || fail 'snapshot root permissions could not be restricted'
[[ "$(stat -c '%a' "$snapshot_root")" == '700' ]] || fail 'snapshot root permissions are not 0700'

checksum_line=$(head -n 1 "$database_checksum")
if [[ ! "$checksum_line" =~ ^([a-f0-9]{64})[[:space:]]+\*?([^/[:space:]]+)$ ]]; then
    fail 'database checksum format is invalid'
fi
expected_database_hash="${BASH_REMATCH[1]}"
checksum_filename="${BASH_REMATCH[2]}"
[[ "$checksum_filename" == "$(basename "$database_backup")" ]] || fail 'database checksum references a different file'
actual_database_hash=$(sha256sum "$database_backup" | awk '{print $1}')
[[ "$actual_database_hash" == "$expected_database_hash" ]] || fail 'database checksum verification failed'

staging="$snapshot_root/.${snapshot_id}.tmp"
final="$snapshot_root/$snapshot_id"
[[ ! -e "$staging" ]] || fail 'snapshot staging directory already exists'
[[ ! -e "$final" ]] || fail 'final snapshot directory already exists'

finalized=0
cleanup() {
    code=$?
    if [[ "$finalized" -eq 0 && -d "$staging" ]]; then
        rm -rf -- "$staging"
    fi
    exit "$code"
}
trap cleanup EXIT

mkdir "$staging"
chmod 700 "$staging"

cp -- "$database_backup" "$staging/database.sql"
printf '%s  database.sql\n' "$actual_database_hash" > "$staging/database.sql.sha256"
cp -- "$environment_file" "$staging/production.env"
chmod 600 "$staging/database.sql" "$staging/database.sql.sha256" "$staging/production.env"

if [[ "$local_test_failure" == 'archive' ]]; then
    fail 'simulated archive failure'
fi

release_parent=$(dirname "$release_root")
release_name=$(basename "$release_root")
tar -C "$release_parent" \
    --exclude="$release_name/.env" \
    --exclude="$release_name/.git" \
    --exclude="$release_name/*.log" \
    --exclude="$release_name/logs" \
    --exclude="$release_name/backups" \
    --exclude="$release_name/var/cache" \
    --exclude="$release_name/var/sessions" \
    --exclude="$release_name/storage/cache" \
    --exclude="$release_name/storage/sessions" \
    -czf "$staging/release.tar.gz" "$release_name"
[[ -s "$staging/release.tar.gz" ]] || fail 'release archive is empty'
chmod 600 "$staging/release.tar.gz"

release_archive_hash=$(sha256sum "$staging/release.tar.gz" | awk '{print $1}')
cat > "$staging/STATE.txt" <<EOF
snapshot_created_at=$(date --iso-8601=seconds)
release_identifier=$release_name
release_path=$release_root
database_backup_source=$(basename "$database_backup")
database_backup_checksum=PASS
release_archive_sha256=$release_archive_hash
tool_version=phase7.4-v1
EOF
chmod 600 "$staging/STATE.txt"

(
    cd "$staging"
    sha256sum database.sql database.sql.sha256 production.env release.tar.gz STATE.txt > SNAPSHOT.sha256
)
chmod 600 "$staging/SNAPSHOT.sha256"

if [[ "$local_test_failure" == 'manifest' ]]; then
    printf '\nsimulated-manifest-change\n' >> "$staging/STATE.txt"
fi

required=(database.sql database.sql.sha256 production.env release.tar.gz STATE.txt SNAPSHOT.sha256)
for artifact in "${required[@]}"; do
    [[ -f "$staging/$artifact" && -s "$staging/$artifact" ]] || fail 'required snapshot artifact is missing or empty'
    [[ "$(stat -c '%a' "$staging/$artifact")" == '600' ]] || fail 'snapshot artifact permissions are not 0600'
done

mapfile -t actual_files < <(find "$staging" -mindepth 1 -maxdepth 1 -type f -printf '%f\n' | sort)
mapfile -t expected_files < <(printf '%s\n' "${required[@]}" | sort)
[[ "${actual_files[*]}" == "${expected_files[*]}" ]] || fail 'snapshot artifact set is not exact'

(cd "$staging" && sha256sum -c --status SNAPSHOT.sha256) || fail 'snapshot manifest verification failed'
[[ "$(stat -c '%a' "$staging")" == '700' ]] || fail 'snapshot directory permissions are not 0700'

mv -- "$staging" "$final"
finalized=1
trap - EXIT

printf 'State snapshot completed: %s\n' "$final"
printf 'Required artifacts verified: %d\n' "${#required[@]}"
