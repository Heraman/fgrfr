// HLS (.m3u8) -> MP4 segmented (10s) -> upload Catbox -> hapus lokal
// Jalankan: SEG_DUR=10 USERHASH=<hash> node hls_catbox.mjs "<URL.m3u8>" [outdir]

import { Catbox } from 'node-catbox';
import { spawn } from 'node:child_process';
import fs from 'node:fs';
import { promises as fsp } from 'node:fs';
import path from 'node:path';

const M3U8_URL = process.argv[2];
const OUT_DIR  = process.argv[3] || 'out';
const SEG_DUR  = parseInt(process.env.SEG_DUR || '10', 10);
const USERHASH = process.env.USERHASH || '578e5c319d525e236a516b876'; // ganti via env kalau perlu

if (!M3U8_URL) {
  console.error('Usage: SEG_DUR=10 USERHASH=<hash> node hls_catbox.mjs "<URL.m3u8>" [outdir]');
  process.exit(1);
}

await fsp.mkdir(OUT_DIR, { recursive: true });
const FAILED_DIR = path.join(OUT_DIR, 'failed');
await fsp.mkdir(FAILED_DIR, { recursive: true });
const UPLOADED_LIST = path.join(OUT_DIR, '.uploaded.list');
if (!fs.existsSync(UPLOADED_LIST)) await fsp.writeFile(UPLOADED_LIST, '');

const catbox = new Catbox(USERHASH);

const sleep = (ms) => new Promise(r => setTimeout(r, ms));
const exists = async (p) => !!(await fsp.stat(p).catch(()=>false));

async function isStable(file) {
  const s1 = await fsp.stat(file).catch(()=>null);
  if (!s1) return false;
  await sleep(300);
  const s2 = await fsp.stat(file).catch(()=>null);
  return !!(s2 && s1.size > 0 && s1.size === s2.size);
}

async function markUploaded(basename, url) {
  await fsp.appendFile(UPLOADED_LIST, `${basename} ${url}\n`);
}

async function alreadyUploaded(basename) {
  const txt = await fsp.readFile(UPLOADED_LIST, 'utf8').catch(()=> '');
  return txt.split('\n').some(line => line.startsWith(basename + ' '));
}

async function uploadOne(fullpath) {
  const base = path.basename(fullpath);
  if (await alreadyUploaded(base)) return;

  try {
    const url = await catbox.uploadFile({ path: fullpath });
    console.log(`☁️  Uploaded: ${base} -> ${url}`);
    await markUploaded(base, url);
    await fsp.unlink(fullpath); // hapus setelah sukses
  } catch (err) {
    console.error(`⚠️  Upload gagal: ${base} | ${err?.message || err}`);
    // pindah ke folder failed biar nggak nyangkut
    const dest = path.join(FAILED_DIR, base);
    await fsp.rename(fullpath, dest).catch(()=>{});
  }
}

function startFfmpeg() {
  // Catatan:
  // -c copy = remux cepat (asumsi H.264/AAC); kalau tidak kompatibel, lihat opsi re-encode di bawah.
  const args = [
    '-hide_banner', '-loglevel', 'warning',
    '-i', M3U8_URL,
    '-map', '0',
    '-c', 'copy',
    '-bsf:a', 'aac_adtstoasc',
    '-f', 'segment',
    '-segment_time', String(SEG_DUR),
    '-reset_timestamps', '1',
    '-segment_format', 'mp4',
    path.join(OUT_DIR, 'seg_%06d.mp4')
  ];

  console.log(`▶️  ffmpeg mulai segmenting (${SEG_DUR}s) ke: ${OUT_DIR}`);
  const ff = spawn('ffmpeg', args, { stdio: ['ignore', 'inherit', 'inherit'] });

  ff.on('exit', (code) => {
    console.log(`ℹ️  ffmpeg selesai (code: ${code}).`);
    ffmpegRunning = false;
  });

  return ff;
}

let ffmpegRunning = true;
const ff = startFfmpeg();

// cleanup bila Ctrl+C / kill
function cleanup() {
  if (ff && ff.pid) {
    try { process.kill(ff.pid); } catch {}
  }
}
process.on('SIGINT', () => { cleanup(); process.exit(0); });
process.on('SIGTERM', () => { cleanup(); process.exit(0); });

// antrean upload sederhana (maks 2 paralel)
const uploading = new Set();
const MAX_PAR = 2;

async function processBatch() {
  const files = (await fsp.readdir(OUT_DIR))
    .filter(n => n.startsWith('seg_') && n.endsWith('.mp4'))
    .sort(); // urutkan lama -> baru

  for (const name of files) {
    if (uploading.size >= MAX_PAR) break;
    const full = path.join(OUT_DIR, name);
    if (uploading.has(name)) continue;
    if (!(await exists(full))) continue;
    if (!(await isStable(full))) continue;

    uploading.add(name);
    uploadOne(full).finally(() => uploading.delete(name));
  }
}

// loop polling sampai ffmpeg stop, lalu sapu sisa
while (ffmpegRunning) {
  await processBatch();
  await sleep(1000);
}
await sleep(500); // beri jeda file terakhir flush
await processBatch();

console.log('✅ Selesai. Cek daftar sukses di', UPLOADED_LIST);
console.log('   Yang gagal (jika ada) ada di', FAILED_DIR);

// ---- OPSI RE-ENCODE (kalau MP4 gagal diputar), ganti argumen ffmpeg di startFfmpeg():
// const args = [
//   '-hide_banner','-loglevel','warning','-i',M3U8_URL,'-map','0',
//   '-c:v','libx264','-preset','veryfast','-crf','23',
//   '-c:a','aac','-b:a','128k','-movflags','+faststart',
//   '-f','segment','-segment_time',String(SEG_DUR),'-reset_timestamps','1',
//   '-segment_format','mp4', path.join(OUT_DIR,'seg_%06d.mp4')
// ];
