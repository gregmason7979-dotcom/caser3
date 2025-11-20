(function () {
  function ready(fn) {
    if (document.readyState === 'loading') {
      document.addEventListener('DOMContentLoaded', fn, { once: true });
    } else {
      fn();
    }
  }

  function ensureStyles() {
    if (document.getElementById('close-confirm-styles')) {
      return;
    }
    const style = document.createElement('style');
    style.id = 'close-confirm-styles';
    style.textContent = `
      .close-confirm-overlay {
        position: fixed;
        inset: 0;
        background: rgba(15, 23, 42, 0.6);
        display: none;
        align-items: center;
        justify-content: center;
        padding: 1.5rem;
        z-index: 9999;
      }
      .close-confirm-overlay[aria-hidden="false"] {
        display: flex;
      }
      .close-confirm-dialog {
        background: #fff;
        border-radius: 12px;
        box-shadow: 0 20px 45px rgba(15, 23, 42, 0.25);
        max-width: 420px;
        width: 100%;
        padding: 1.5rem;
        font-family: inherit;
      }
      .close-confirm-dialog h3 {
        margin-top: 0;
        margin-bottom: 0.5rem;
        font-size: 1.15rem;
      }
      .close-confirm-dialog p {
        margin: 0 0 1.25rem;
        color: #374151;
        line-height: 1.4;
      }
      .close-confirm-actions {
        display: flex;
        justify-content: flex-end;
        gap: 0.75rem;
      }
      .close-confirm-actions button {
        font-size: 0.95rem;
        border-radius: 999px;
        border: none;
        padding: 0.5rem 1.25rem;
        cursor: pointer;
        font-weight: 600;
      }
      .close-confirm-actions button[data-close-confirm-cancel] {
        background: #f3f4f6;
        color: #374151;
      }
      .close-confirm-actions button[data-close-confirm-proceed] {
        background: #ef4444;
        color: #fff;
      }
      body.close-confirm-open {
        overflow: hidden;
      }
    `;
    document.head.appendChild(style);
  }

  function ensureOverlay() {
    let overlay = document.getElementById('closeConfirmOverlay');
    if (overlay) {
      return overlay;
    }

    overlay = document.createElement('div');
    overlay.id = 'closeConfirmOverlay';
    overlay.className = 'close-confirm-overlay';
    overlay.setAttribute('role', 'dialog');
    overlay.setAttribute('aria-modal', 'true');
    overlay.setAttribute('aria-hidden', 'true');
    overlay.innerHTML = `
      <div class="close-confirm-dialog" role="document">
        <h3>Close case?</h3>
        <p data-close-confirm-message>Are you sure you want to close this case?</p>
        <div class="close-confirm-actions">
          <button type="button" data-close-confirm-cancel>Cancel</button>
          <button type="button" data-close-confirm-proceed>Close case</button>
        </div>
      </div>
    `;
    document.body.appendChild(overlay);
    return overlay;
  }

  function bindCloseLinks() {
    ensureStyles();
    const overlay = ensureOverlay();
    const messageEl = overlay.querySelector('[data-close-confirm-message]');
    const cancelBtn = overlay.querySelector('[data-close-confirm-cancel]');
    const proceedBtn = overlay.querySelector('[data-close-confirm-proceed]');
    const titleEl = overlay.querySelector('h3');
    let pendingUrl = '';

    function hideOverlay() {
      overlay.setAttribute('aria-hidden', 'true');
      document.body.classList.remove('close-confirm-open');
      pendingUrl = '';
    }

    function showOverlay(message, url, titleText) {
      if (!messageEl || !proceedBtn) {
        window.location.href = url;
        return;
      }
      pendingUrl = url;
      if (titleEl) {
        titleEl.textContent = titleText || 'Close case?';
      }
      messageEl.textContent = message || 'Are you sure you want to close this case?';
      overlay.setAttribute('aria-hidden', 'false');
      document.body.classList.add('close-confirm-open');
      try {
        proceedBtn.focus();
      } catch (err) {
        // ignore
      }
    }

    cancelBtn?.addEventListener('click', hideOverlay);
    overlay.addEventListener('click', (event) => {
      if (event.target === overlay) {
        hideOverlay();
      }
    });

    proceedBtn?.addEventListener('click', () => {
      if (pendingUrl) {
        window.location.href = pendingUrl;
      }
    });

    document.querySelectorAll('[data-close-confirm]').forEach(link => {
      link.addEventListener('click', (event) => {
        event.preventDefault();
        const url = link.getAttribute('href');
        if (!url) {
          return;
        }
        const message = link.getAttribute('data-close-confirm') || link.getAttribute('data-close-message');
        const titleText = link.getAttribute('data-close-title') || 'Close case?';
        showOverlay(message, url, titleText);
      });
    });
  }

  ready(bindCloseLinks);
})();
