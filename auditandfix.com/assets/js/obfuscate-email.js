/**
 * Email obfuscation decoder
 *
 * Finds all .obf-email elements, decodes ROT13 from data-u + data-h,
 * sets the href and visible text at runtime so no plain address is
 * present in the HTML source.
 */
(function () {
  'use strict';

  function rot13(s) {
    return s.replace(/[a-zA-Z]/g, function (c) {
      var base = c <= 'Z' ? 65 : 97;
      return String.fromCharCode(((c.charCodeAt(0) - base + 13) % 26) + base);
    });
  }

  function decodeLinks() {
    document.querySelectorAll('a.obf-email').forEach(function (el) {
      var u = rot13(el.dataset.u || '');
      var h = rot13(el.dataset.h || '');
      if (!u || !h) return;
      var addr = u + '\u0040' + h; // \u0040 = @
      el.href = 'mailto:' + addr;
      // Only overwrite text if it's still the zero-width placeholder
      if (el.textContent.trim() === '' || el.textContent === '\u200B') {
        el.textContent = addr;
      }
    });
  }

  if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', decodeLinks);
  } else {
    decodeLinks();
  }
})();
