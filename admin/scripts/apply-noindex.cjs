const fs = require('fs');
const path = require('path');

const root = process.cwd();
const robotsMeta = '<meta name="robots" content="index, follow">';
const robotsPattern = /<meta\b(?=[^>]*\bname\s*=\s*["']?robots["']?)[^>]*>/i;

function walk(dir) {
  for (const entry of fs.readdirSync(dir, { withFileTypes: true })) {
    if (entry.name === 'uploads') continue;

    const fullPath = path.join(dir, entry.name);
    if (entry.isDirectory()) {
      walk(fullPath);
      continue;
    }

    if (!/\.(html|php)$/i.test(entry.name)) continue;

    let source = fs.readFileSync(fullPath, 'utf8');
    if (robotsPattern.test(source)) {
      source = source.replace(robotsPattern, robotsMeta);
    } else if (/<head[^>]*>/i.test(source)) {
      source = source.replace(/<head[^>]*>/i, (match) => `${match}\n  ${robotsMeta}`);
    }

    fs.writeFileSync(fullPath, source);
  }
}

walk(root);
