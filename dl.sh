#!/usr/bin/env bash
# HLS (.m3u8) -> MP4 segmented (10s) -> upload ke Catbox -> hapus lokal
# by zilfa + helper

set -u -o pipefail

M3U8_URL="${1:-}"
OUT_DIR="${2:-out}"
SEG_DUR="${SEG_DUR:-10}"  # durasi per segmen detik (default 10)
USERHASH="${USERHASH:-578e5c319d525e236a516b876}"  # ganti env kalau perlu
CATBOX_ENDPOINT="https://catbox.moe/user/api.php"

if [[ -z "$M3U8_URL" ]]; then
  echo "Usage: $0 <URL.m3u8> [output_dir]"
  echo "Env vars: SEG_DUR=10 USERHASH=xxxx"
  exit 1
fi

mkdir -p "$OUT_DIR"
UPLOADED_LIST="$OUT_DIR/.uploaded.list"
FAILED_DIR="$OUT_DIR/failed"
mkdir -p "$FAILED_DIR"
touch "$UPLOADED_LIST"

# Cek dependensi
command -v ffmpeg >/dev/null 2>&1 || { echo "ffmpeg tidak ditemukan. apt-get install ffmpeg"; exit 2; }
command -v curl   >/dev/null 2>&1 || { echo "curl tidak ditemukan. apt-get install curl"; exit 2; }

# Upload fungsi
upload_one() {
  local file="$1"
  # skip kalau sudah pernah
  grep -Fxq "$(basename "$file")" "$UPLOADED_LIST" && return 0

  # upload (paksa HTTP/1.1, non-100-continue)
  local resp
  if ! resp=$(
    curl --http1.1 -fsS -m 180 \
      -H 'Expect:' -H 'Connection: close' \
      -F "reqtype=fileupload" \
      -F "userhash=${USERHASH}" \
      -F "fileToUpload=@${file}" \
      "$CATBOX_ENDPOINT"
  ); then
    echo "⚠️  Upload gagal (curl exit) -> $(basename "$file")"
    mv -f "$file" "$FAILED_DIR/" 2>/dev/null || true
    return 1
  fi

  resp_trim="$(echo -n "$resp" | tr -d '\r')"
  if [[ "$resp_trim" =~ ^https?:// ]]; then
    echo "☁️  Uploaded: $(basename "$file") -> $resp_trim"
    echo "$(basename "$file")" >> "$UPLOADED_LIST"
    rm -f -- "$file"  # hapus setelah sukses
    return 0
  else
    echo "⚠️  Catbox response non-URL untuk $(basename "$file"): $resp_trim"
    mv -f "$file" "$FAILED_DIR/" 2>/dev/null || true
    return 1
  fi
}

# Matikan ffmpeg saat script dihentikan
ffmpeg_pid=""
cleanup() {
  if [[ -n "$ffmpeg_pid" ]] && kill -0 "$ffmpeg_pid" 2>/dev/null; then
    kill "$ffmpeg_pid" 2>/dev/null || true
    wait "$ffmpeg_pid" 2>/dev/null || true
  fi
}
trap cleanup INT TERM EXIT

echo "▶️  Mulai segmenting $M3U8_URL ke '$OUT_DIR' (durasi ${SEG_DUR}s/segmen)..."

# Catatan:
# -c copy = remux tanpa re-encode (cepat; cocok bila codec H.264/AAC)
# -bsf:a aac_adtstoasc = perbaiki header AAC ke MP4
# -f segment -segment_time SEG_DUR -reset_timestamps 1 = potong per n detik
# -segment_format mp4 = kontainer mp4 per segmen
# Kalau stream pakai codec 'aneh' (bukan H.264/AAC) dan hasil MP4 nggak kebaca,
# ganti baris -c copy menjadi re-encode: -c:v libx264 -c:a aac -movflags +faststart
ffmpeg -hide_banner -loglevel warning \
  -i "$M3U8_URL" \
  -map 0 -c copy -bsf:a aac_adtstoasc \
  -f segment -segment_time "$SEG_DUR" -reset_timestamps 1 \
  -segment_format mp4 \
  "$OUT_DIR/seg_%06d.mp4" &

ffmpeg_pid=$!

# Loop polling file baru & upload
# (pakai polling agar tidak tergantung inotify-tools)
last_count=0
while kill -0 "$ffmpeg_pid" 2>/dev/null; do
  # Ambil file yang sudah selesai ditulis (utamakan yang paling lama)
  for f in "$OUT_DIR"/seg_*.mp4; do
    [[ -e "$f" ]] || continue
    # bila ukuran masih berubah, tunda
    size1=$(stat -c%s "$f" 2>/dev/null || echo 0)
    sleep 0.3
    size2=$(stat -c%s "$f" 2>/dev/null || echo 0)
    if [[ "$size1" -gt 0 && "$size1" -eq "$size2" ]]; then
      upload_one "$f" || true
    fi
  done
  sleep 1
done

# Sapu sisa file yang belum ter-upload (kalau ada)
for f in "$OUT_DIR"/seg_*.mp4; do
  [[ -e "$f" ]] || continue
  upload_one "$f" || true
done

echo "✅ Selesai. Lihat daftar yang sukses di: $UPLOADED_LIST"
echo "   File gagal (jika ada) dipindah ke: $FAILED_DIR/"
