(function () {
  'use strict';

  function getHelperUrl() {
    const override = (window.CSTA_HELPER_URL || '').trim();
    return override || 'csta_makecall.php';
  }

  function ensureAgentExt(value) {
    const ext = (value || '').trim();
    if (ext) {
      return ext;
    }
    alert('Missing agent extension. Append ?ext=200 to the URL or load the form from MiCC-E Agent.');
    return '';
  }

  function ensureNumber(value) {
    const number = (value || '').trim();
    if (number) {
      return number;
    }
    alert('Enter a destination phone number first.');
    return '';
  }

  function mxoneMakeCall(fromExt, toNumber) {
    const ext = ensureAgentExt(fromExt);
    const dest = ensureNumber(toNumber);
    if (!ext || !dest) {
      return Promise.reject(new Error('Missing extension or destination'));
    }

    const url = getHelperUrl()
      + '?from=' + encodeURIComponent(ext)
      + '&to=' + encodeURIComponent(dest);

    return fetch(url, { method: 'GET' })
      .then(r => r.text())
      .then(text => {
        let data;
        try {
          data = JSON.parse(text);
        } catch (err) {
          alert('Helper did not return JSON. First 200 chars:\n' + text.substring(0, 200));
          throw err;
        }

        if (data.success) {
          alert('Dialling ' + dest + ' from ' + ext);
          return data;
        }

        const message = data.message || 'Unknown error';
        alert('MakeCall failed: ' + message);
        throw new Error(message);
      })
      .catch(err => {
        console.error('Error calling MX-ONE helper:', err);
        alert('Error calling MX-ONE helper: ' + (err && err.message ? err.message : err));
        throw err;
      });
  }

  function attachCstaLinks(scope) {
    const root = scope || document;
    if (!root || typeof root.querySelectorAll !== 'function') {
      return;
    }

    root.querySelectorAll('[data-csta-number]').forEach(link => {
      if (link.dataset.cstaBound === '1') {
        return;
      }
      link.dataset.cstaBound = '1';

      link.addEventListener('click', evt => {
        evt.preventDefault();
        const number = link.getAttribute('data-csta-number') || link.textContent || '';
        const dest = number.trim();
        mxoneMakeCall(window.AGENT_EXT || '', dest);
      });

      if (!link.getAttribute('title')) {
        const ext = (window.AGENT_EXT || '').trim();
        link.setAttribute('title', ext ? `Dial via ${ext}` : 'Set your MiCC-E extension to enable click-to-call.');
      }
    });
  }

  window.mxoneMakeCall = mxoneMakeCall;
  window.attachCstaLinks = attachCstaLinks;

  document.addEventListener('DOMContentLoaded', () => {
    attachCstaLinks(document);
  });
})();
