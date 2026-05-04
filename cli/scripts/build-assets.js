import fs from 'fs';
import path from 'path';
import { fileURLToPath } from 'url';

const __filename = fileURLToPath(import.meta.url);
const __dirname = path.dirname(__filename);

const ROOT_DIR = path.resolve(__dirname, '../../');
const ASSETS_DIR = path.resolve(__dirname, '../assets');

// Directories/files to copy from root into cli/assets
const TO_COPY = [
  'skills',
  'docs',
  'scripts',
  'examples',
  'skill.json',
  'README.md',
  'CLAUDE.md'
];

function copyRecursiveSync(src, dest) {
  const exists = fs.existsSync(src);
  const stats = exists && fs.statSync(src);
  const isDirectory = exists && stats.isDirectory();
  if (isDirectory) {
    if (!fs.existsSync(dest)) fs.mkdirSync(dest, { recursive: true });
    fs.readdirSync(src).forEach((childItemName) => {
      copyRecursiveSync(path.join(src, childItemName), path.join(dest, childItemName));
    });
  } else if (exists) {
    fs.copyFileSync(src, dest);
  }
}

if (!fs.existsSync(ASSETS_DIR)) {
  fs.mkdirSync(ASSETS_DIR, { recursive: true });
} else {
  // Clear existing assets except what's not ours
  fs.rmSync(ASSETS_DIR, { recursive: true, force: true });
  fs.mkdirSync(ASSETS_DIR, { recursive: true });
}

console.log('Copying assets from root...');
for (const item of TO_COPY) {
  const src = path.join(ROOT_DIR, item);
  const dest = path.join(ASSETS_DIR, item);
  if (fs.existsSync(src)) {
    console.log(`Copying ${item}...`);
    copyRecursiveSync(src, dest);
  } else {
    console.warn(`Warning: Could not find ${src}`);
  }
}

console.log('Assets successfully built.');
