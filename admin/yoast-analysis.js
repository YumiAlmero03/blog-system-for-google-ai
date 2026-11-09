/* Yoast result adapters and non-destructive editor highlighting. */
(() => {
  const value = (result, method, property, fallback) => typeof result?.[method] === 'function'
    ? result[method]() : result?.[property] ?? fallback;
  const plain = html => {
    const template = document.createElement('template');
    template.innerHTML = String(html || '').replace(/<br\s*\/?\s*>|<\/(?:p|div|li|h[1-6])>/gi, ' ');
    return (template.content.textContent || '').replace(/\s+/g, ' ').trim();
  };
  const fold = s => String(s || '')
    .replace(/[\u2018\u2019\u201A\u201B\u2032]/g, "'")
    .replace(/[\u201C\u201D\u201E\u201F\u2033]/g, '"')
    .replace(/[\u2013\u2014]/g, '-')
    .replace(/\u00A0/g, ' ');
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
  function checks(items, sourceText = '') {
    const list = Array.isArray(items) ? items : results(items);
    const textSource = sourceText || (typeof items?._lastPaper?.getText === 'function' ? items._lastPaper.getText() : '');
    return list.flatMap((result, index) => {
      if (typeof result?.hasScore === 'function' && !result.hasScore()) return [];
      if (typeof result?.hasText === 'function' && !result.hasText()) return [];
      const label = plain(value(result, 'getText', 'text', ''));
      if (!label) return [];
      const score = Number(value(result, 'getScore', 'score', NaN));
      const marks = value(result, 'getMarks', 'marks', []);
      const passages = Array.isArray(marks) ? marks.flatMap(mark => {
        const fields = value(mark, 'getFieldsToMark', 'fieldsToMark', []);
        if (fields.length && !fields.includes('text')) return [];
        let text = plain(value(mark, 'getOriginal', 'original', mark?._properties?.original || ''));
        if (!text && textSource) {
          const pos = typeof mark?.getPosition === 'function' ? mark.getPosition() : mark?._properties?.position;
          if (pos && typeof pos.startOffset === 'number' && typeof pos.endOffset === 'number') {
            text = plain(textSource.slice(pos.startOffset, pos.endOffset));
          }
        }
        return text ? [text] : [];
      }) : [];
      return [{ id: String(value(result, 'getIdentifier', '_identifier', index)), label, state: checkState(score), passages }];
    });
  }
  function ranges(root, passages) {
    if (!root || !Array.isArray(passages) || !passages.length) return [];
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
    const foldedText = fold(text);
    const found = [], seen = new Set();
    for (const passage of new Set(passages)) {
      const needle = passage.replace(/\s+/g, ' ').trim();
      if (!needle) continue;
      const foldedNeedle = fold(needle);
      const candidates = [foldedNeedle];
      const stripped = foldedNeedle.replace(/^[.,:;!?'"“”‘’()\[\]]+|[.,:;!?'"“”‘’()\[\]]+$/g, '').trim();
      if (stripped && stripped !== foldedNeedle && stripped.length >= 10) {
        candidates.push(stripped);
      }
      for (const target of candidates) {
        let offset = 0, start;
        let matched = false;
        while ((start = foldedText.indexOf(target, offset)) !== -1) {
          const end = start + target.length - 1;
          let startPos = start;
          while (startPos <= end && !positions[startPos]) startPos++;
          let endPos = end;
          while (endPos >= startPos && !positions[endPos]) endPos--;
          const key = `${startPos}:${endPos}`;
          if (positions[startPos] && positions[endPos] && !seen.has(key)) {
            const range = document.createRange();
            range.setStart(positions[startPos].node, positions[startPos].offset);
            range.setEnd(positions[endPos].node, positions[endPos].offset + 1);
            found.push(range);
            seen.add(key);
            matched = true;
          }
          offset = start + target.length;
        }
        if (matched) break;
      }
    }
    return found;
  }
  window.BlogYoastAnalysis = { checks, results, checkState, ranges };
})();
