import fs from 'fs';
import path from 'path';
import os from 'os';
import chalk from 'chalk';

export const AI_TYPES = [
  'claude', 'cursor', 'windsurf', 'antigravity', 'copilot',
  'kiro', 'roocode', 'codex', 'qoder', 'gemini', 'trae',
  'opencode', 'continue', 'codebuddy', 'droid', 'kilocode',
  'warp', 'augment', 'all'
];

const AI_FOLDERS = {
  claude: ['.claude'],
  cursor: ['.cursor', '.shared'],
  windsurf: ['.windsurf', '.shared'],
  antigravity: ['.agents', '.shared'],
  copilot: ['.github', '.shared'],
  kiro: ['.kiro', '.shared'],
  codex: ['.codex'],
  roocode: ['.roo', '.shared'],
  qoder: ['.qoder', '.shared'],
  gemini: ['.gemini', '.shared'],
  trae: ['.trae', '.shared'],
  opencode: ['.opencode', '.shared'],
  continue: ['.continue'],
  codebuddy: ['.codebuddy'],
  droid: ['.factory'],
  kilocode: ['.kilocode', '.shared'],
  warp: ['.warp', '.shared'],
  augment: ['.augment', '.shared'],
};

export function copyRecursiveSync(src, dest) {
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

export function installSkills(aiType, assetsDir, isGlobal) {
  const homedir = os.homedir();
  const cwd = process.cwd();

  const typesToInstall = aiType === 'all' 
    ? AI_TYPES.filter(t => t !== 'all') 
    : [aiType];

  let successCount = 0;

  for (const type of typesToInstall) {
    const folders = AI_FOLDERS[type];
    if (!folders) continue;

    for (const folder of folders) {
      if (folder === '.shared') continue;

      const basePath = isGlobal ? homedir : cwd;
      // In Antigravity it is usually .agents/workflows or .agents/skills? 
      // We will install into <AI_DIR>/skills/pocketmine-plugin-dev
      const targetDir = path.join(basePath, folder, 'skills', 'pocketmine-plugin-dev');

      console.log(chalk.cyan(`Installing to ${targetDir}...`));

      try {
        if (!fs.existsSync(targetDir)) {
          fs.mkdirSync(targetDir, { recursive: true });
        }
        
        copyRecursiveSync(assetsDir, targetDir);
        successCount++;
        console.log(`  ${chalk.green('+')} Copied files to ${folder}/skills`);
      } catch (err) {
        console.error(chalk.red(`  ${chalk.red('x')} Failed copying to ${folder}/skills:`), err.message);
      }
    }
  }

  return successCount > 0;
}
