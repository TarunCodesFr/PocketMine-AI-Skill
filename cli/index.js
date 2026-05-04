#!/usr/bin/env node

import { Command } from 'commander';
import chalk from 'chalk';
import prompts from 'prompts';
import path from 'path';
import { fileURLToPath } from 'url';
import fs from 'fs';
import { AI_TYPES, installSkills } from './utils.js';

const __filename = fileURLToPath(import.meta.url);
const __dirname = path.dirname(__filename);
const packageJsonPath = path.join(__dirname, 'package.json');
const pkg = JSON.parse(fs.readFileSync(packageJsonPath, 'utf-8'));

const ASSETS_DIR = path.join(__dirname, 'assets');

const program = new Command();

program
  .name('pocketmine-skill')
  .description('CLI to install PocketMine AI Skill for developers')
  .version(pkg.version);

program
  .command('init')
  .description('Install PocketMine Plugin Dev skill to current project')
  .option('-a, --ai <type>', `AI assistant type (${AI_TYPES.join(', ')})`)
  .option('-g, --global', 'Install globally to home directory (~/) instead of current project')
  .action(async (options) => {
    let aiType = options.ai;

    if (aiType && !AI_TYPES.includes(aiType)) {
      console.error(chalk.red(`Invalid AI type: ${aiType}`));
      console.error(`Valid types: ${AI_TYPES.join(', ')}`);
      process.exit(1);
    }

    if (!aiType) {
      console.log(chalk.bold.blue('\nPocketMine AI Skill Installer\n'));
      const response = await prompts({
        type: 'select',
        name: 'aiType',
        message: 'Select AI assistant to install for:',
        choices: AI_TYPES.map(type => ({
          title: type.charAt(0).toUpperCase() + type.slice(1),
          value: type,
        })),
        initial: 0,
      });

      if (!response.aiType) {
        console.log(chalk.yellow('Installation cancelled'));
        return;
      }
      aiType = response.aiType;
    }

    const isGlobal = !!options.global;
    
    // Ensure assets are available
    if (!fs.existsSync(ASSETS_DIR)) {
      console.error(chalk.red("Assets not found. Please run 'npm run prepack' inside the cli/ directory first."));
      console.log(chalk.dim(`Expected assets at: ${ASSETS_DIR}`));
      process.exit(1);
    }

    console.log(chalk.cyan(`\nStarting installation for ${chalk.bold(aiType)}${isGlobal ? ' (Global)' : ''}...`));
    
    const success = installSkills(aiType, ASSETS_DIR, isGlobal);

    if (success) {
      console.log(chalk.green.bold('\nPocketMine AI Skill installed successfully!'));
      console.log(chalk.dim('  1. Restart your AI coding assistant'));
      console.log(chalk.dim('  2. Try: "Create a Spleef minigame plugin with arena management"'));
      console.log();
    } else {
      console.log(chalk.red('\nInstallation failed or no compatible folders were configured.'));
    }
  });

program.parse();
