const fs = require('node:fs');
const vm = require('node:vm');
const assert = require('node:assert/strict');
const context = vm.createContext({ URL });
vm.runInContext(fs.readFileSync(require('node:path').join(__dirname, '../../chat-message.js'), 'utf8').replace('export function', 'function'), context);
const document = {
  createTextNode: text => ({ textContent: text }),
  createDocumentFragment: () => ({ children: [], append(...nodes) { this.children.push(...nodes); } }),
  createElement: tag => ({ tag, setAttribute(key, value) { this[key] = value; } }),
};
const element = { ownerDocument: document, style: {}, replaceChildren(fragment) { this.children = fragment.children; } };
function render(text) { context.renderChatMessage(element, text); return element.children.filter(node => node.tag === 'a'); }
let links = render('Step 1\r\n\r\n  Visit https://example.com/help?q=1&x=2.\n[Register](/register) or www.example.com');
assert.deepEqual(links.map(link => link.href), ['https://example.com/help?q=1&x=2', '/register', 'https://www.example.com']);
assert.equal(links[1].textContent, 'Register');
assert(element.children.map(node => node.textContent).join('').startsWith('Step 1\n\n  Visit'));
assert.equal(element.style.whiteSpace, 'pre-wrap');
assert(links.every(link => link.target === '_blank' && link.rel === 'noopener noreferrer'));
links = render('<img src=x onerror=alert(1)>\n[bad](javascript:alert(1)) [bad](//evil.test) [bad](/\\evil.test)');
assert.equal(links.length, 0);
assert(element.children.map(node => node.textContent).join('').includes('<img src=x onerror=alert(1)>'));
links = render('See (https://example.com/help).');
assert.equal(links[0].href, 'https://example.com/help');
assert(element.children.map(node => node.textContent).join('').endsWith(').'));
console.log('PASS: clickable links, multiline spacing, punctuation, safe protocols and literal HTML.');
