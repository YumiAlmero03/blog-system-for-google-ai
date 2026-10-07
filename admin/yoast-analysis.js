/* Yoast result adapters and non-destructive editor highlighting. */
(() => {
  const value = (result, method, property, fallback) => typeof result?.[method] === 'function'
    ? result[method]() : result?.[property] ?? fallback;
  const plain = html => {
    const template = document.createElement('template');
    template.innerHTML = String(html || '').replace(/<br\s*\/?\s*>|<\/(?:p|div|li|h[1-6])>/gi, ' ');
    return (template.content.textContent || '').replace(/\s+/g, ' ').trim();
  };
  function checkState(score) {
    if (!Number.isFinite(score) || score <= 0) return 'neutral';
    const normalized = score > 10 ? score / 10 : score;
    return normalized >= 8 ? 'good' : normalized >= 5 ? 'warn' : 'bad';
  }
  function results(assessor) {
    if (typeof assessor?.getValidResults === 'function') return assessor.getValidResults();
    if (Array.isArray(assessor?.results)) return assessor.results;
    if (typeof assessor?.getAllResults === 'function') return assessor.getAllResults();
    if (typeof assessor?.getResults === 'function') return assessor.getResults();
    return [];
  }
  function checks(items) {
    if (!Array.isArray(items)) return [];
    return items.flatMap((result, index) => {
      if (typeof result?.hasScore === 'function' && !result.hasScore()) return [];
      if (typeof result?.hasText === 'function' && !result.hasText()) return [];
      const label = plain(value(result, 'getText', 'text', ''));
      if (!label) return [];
      const score = Number(value(result, 'getScore', 'score', NaN));
      const marks = value(result, 'getMarks', 'marks', []);
      const passages = Array.isArray(marks) ? marks.flatMap(mark => {
        const fields = value(mark, 'getFieldsToMark', 'fieldsToMark', []);
        if (fields.length && !fields.includes('text')) return [];
        const text = plain(value(mark, 'getOriginal', 'original', mark?._properties?.original || ''));
        return text ? [text] : [];
      }) : [];
      return [{ id: String(value(result, 'getIdentifier', '_identifier', index)), label, state: checkState(score), passages }];
    });
  }
  function ranges(root, passages) {
    if (!root) return [];
    const walker = document.createTreeWalker(root, NodeFilter.SHOW_TEXT | NodeFilter.SHOW_ELEMENT, {
      acceptNode(node) {
        const element = node.nodeType === Node.ELEMENT_NODE ? node : node.parentElement;
        if (element?.closest('script,style,textarea,input,button,select,[contenteditable="false"],.editor-block-close,.editor-custom-code-block')) return NodeFilter.FILTER_REJECT;
        return node.nodeType === Node.TEXT_NODE || node.nodeName === 'BR' ? NodeFilter.FILTER_ACCEPT : NodeFilter.FILTER_SKIP;
      },
    });
    let text = '', previousBlock = null;
    const positions = [];
    for (let node = walker.nextNode(); node; node = walker.nextNode()) {
      if (node.nodeName === 'BR') {
        if (!text.endsWith(' ')) { text += ' '; positions.push(null); }
        continue;
      }
      const block = node.parentElement.closest('p,li,h1,h2,h3,h4,h5,h6,blockquote,td,th,section,div');
      if (previousBlock && block !== previousBlock && !text.endsWith(' ')) { text += ' '; positions.push(null); }
      previousBlock = block;
      for (let offset = 0; offset < node.textContent.length; offset++) {
        const char = /\s/.test(node.textContent[offset]) ? ' ' : node.textContent[offset];
        if (char === ' ' && text.endsWith(' ')) continue;
        text += char; positions.push({ node, offset });
      }
    }
    const found = [], seen = new Set();
    for (const passage of new Set(passages)) {
      const needle = passage.replace(/\s+/g, ' ').trim();
      if (!needle) continue;
      let offset = 0, start;
      while ((start = text.indexOf(needle, offset)) !== -1) {
        const end = start + needle.length - 1;
        const key = `${start}:${end}`;
        if (positions[start] && positions[end] && !seen.has(key)) {
          const range = document.createRange();
          range.setStart(positions[start].node, positions[start].offset);
          range.setEnd(positions[end].node, positions[end].offset + 1);
          found.push(range); seen.add(key);
        }
        offset = start + needle.length;
      }
    }
    return found;
  }
  window.BlogYoastAnalysis = { checks, results, checkState, ranges };
})();
