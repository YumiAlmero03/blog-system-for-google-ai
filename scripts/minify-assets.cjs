const fs = require('fs');

const css = fs
  .readFileSync('assets/css/styles.css', 'utf8')
  .replace(/\/\*[\s\S]*?\*\//g, '')
  .replace(/\s+/g, ' ')
  .replace(/\s*([{}:;,>+~])\s*/g, '$1')
  .replace(/;}/g, '}')
  .trim();

fs.writeFileSync('assets/css/styles.min.css', css);

const isIdent = (char) => /[A-Za-z0-9_$]/.test(char || '');

function minifyJs(input) {
  let out = '';
  let i = 0;
  let state = null;
  let escaped = false;

  while (i < input.length) {
    const char = input[i];
    const next = input[i + 1];

    if (state) {
      out += char;
      if (escaped) {
        escaped = false;
      } else if (char === '\\') {
        escaped = true;
      } else if (char === state) {
        state = null;
      }
      i++;
      continue;
    }

    if (char === '"' || char === "'" || char === '`') {
      state = char;
      out += char;
      i++;
      continue;
    }

    if (char === '/' && next === '/') {
      i += 2;
      while (i < input.length && input[i] !== '\n') i++;
      continue;
    }

    if (char === '/' && next === '*') {
      i += 2;
      while (i < input.length && !(input[i] === '*' && input[i + 1] === '/')) i++;
      i += 2;
      continue;
    }

    if (/\s/.test(char)) {
      const prev = out[out.length - 1];
      const nextNonSpace = input.slice(i + 1).match(/\S/);
      if (isIdent(prev) && isIdent(nextNonSpace ? nextNonSpace[0] : '')) out += ' ';
      i++;
      continue;
    }

    if ('{}[]();,:+-*%<>=!&|?'.includes(char) && out.endsWith(' ')) {
      out = out.slice(0, -1);
    }

    out += char;
    i++;
  }

  return out.trim();
}

fs.writeFileSync('assets/js/main.min.js', minifyJs(fs.readFileSync('assets/js/main.js', 'utf8')));
fs.writeFileSync('assets/js/blog.min.js', minifyJs(fs.readFileSync('assets/js/blog.js', 'utf8')));
