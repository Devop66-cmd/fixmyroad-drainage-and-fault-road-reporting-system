/**
 * FixMyRoad (Kansanga Pilot) - Simple Beginner-Friendly JavaScript Helpers
 * Handles: demo login autofill, admin official notice live preview & expiry presets.
 */

function fillDemo(email) {
  var emailInput = document.getElementById('login-email');
  var passInput = document.getElementById('login-password');
  if (emailInput) emailInput.value = email;
  if (passInput) passInput.value = 'password123';
}

function setNoticeExpiryPreset(days) {
  var inp = document.getElementById('ntcExpiryInput');
  if (!inp) return;
  if (!days) {
    inp.value = '';
    return;
  }
  var d = new Date();
  d.setDate(d.getDate() + days);
  inp.value = d.toISOString().slice(0, 10);
}

window.addEventListener('DOMContentLoaded', function () {
  var titleEl = document.getElementById('ntcTitleInput');
  var catEl = document.getElementById('ntcCatInput');
  var msgEl = document.getElementById('ntcMsgInput');
  var box = document.getElementById('ntcPreviewBox');
  var pTitle = document.getElementById('ntcPreviewTitle');
  var pMsg = document.getElementById('ntcPreviewMsg');

  if (titleEl && catEl && msgEl && box && pTitle && pMsg) {
    function syncPreview() {
      var cat = catEl.value;
      box.className = 'notice-banner ' + (
        cat === 'Emergency' ? 'notice-banner--emergency' :
        cat === 'Maintenance' ? 'notice-banner--maintenance' :
        'notice-banner--general'
      );
      box.style.borderRadius = '12px';
      pTitle.textContent = (titleEl.value.trim() || 'Notice Title').toUpperCase() + ':';
      pMsg.textContent = msgEl.value.trim() || 'Your advisory message will appear in the top municipal banner and citizen notices feed.';
    }
    titleEl.addEventListener('input', syncPreview);
    catEl.addEventListener('change', syncPreview);
    msgEl.addEventListener('input', syncPreview);
    syncPreview();
  }
});
