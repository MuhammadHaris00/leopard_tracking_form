/**
 * Leopard Tracking Form — AJAX inline result with redirect fallback.
 */
(function () {
  document.addEventListener('submit', function (e) {
    var form = e.target;
    if (!form || !form.hasAttribute('data-ltf-form')) {
      return;
    }
    if (typeof LTF_DATA === 'undefined' || !LTF_DATA.ajaxUrl) {
      return; // fallback: normal GET redirect to result page
    }
    e.preventDefault();

    var input = form.querySelector('input[name="ltf_cn"]');
    var box = form.querySelector('[data-ltf-inline-result]');
    var btn = form.querySelector('button[type="submit"]');
    var cn = input ? input.value.trim().toUpperCase() : '';

    if (!cn) {
      return;
    }
    if (box) {
      box.innerHTML = '<p class="ltf-empty">Tracking…</p>';
    }
    if (btn) {
      btn.disabled = true;
    }

    var params = new URLSearchParams();
    params.append('action', 'ltf_track');
    params.append('nonce', LTF_DATA.nonce);
    params.append('cn', cn);

    fetch(LTF_DATA.ajaxUrl, {
      method: 'POST',
      credentials: 'same-origin',
      headers: { 'Content-Type': 'application/x-www-form-urlencoded; charset=UTF-8' },
      body: params.toString()
    })
      .then(function (r) { return r.json(); })
      .then(function (json) {
        if (json && json.success && json.data && json.data.html) {
          if (box) {
            box.innerHTML = json.data.html;
          }
          // Update URL for shareability without reload.
          try {
            var u = new URL(window.location.href);
            u.searchParams.set('ltf_cn', cn);
            window.history.replaceState({}, '', u.toString());
          } catch (err) {}
        } else {
          var msg = (json && json.data && json.data.message) ? json.data.message : 'Tracking failed.';
          // Fallback: redirect to dedicated result page.
          if (LTF_DATA.resultPage) {
            window.location.href = LTF_DATA.resultPage + (LTF_DATA.resultPage.indexOf('?') === -1 ? '?' : '&') + 'ltf_cn=' + encodeURIComponent(cn);
          } else if (box) {
            box.innerHTML = '<div class="ltf-error"><strong>Tracking failed</strong><p>' + msg + '</p></div>';
          }
        }
      })
      .catch(function () {
        if (LTF_DATA.resultPage) {
          window.location.href = LTF_DATA.resultPage + (LTF_DATA.resultPage.indexOf('?') === -1 ? '?' : '&') + 'ltf_cn=' + encodeURIComponent(cn);
        }
      })
      .finally(function () {
        if (btn) {
          btn.disabled = false;
        }
      });
  });
})();
