/* ============================================================
   دروازه ورود/ثبت‌نام — بدون خروج از این صفحه
   ============================================================ */
'use strict';

(function () {
  const CONFIG = window.GoldCrawlerAuthConfig || {};
  const $ = (id) => document.getElementById(id);

  function showMessage(text, ok) {
    const box = $('goldcrawlerAuthMessage');
    if (!box) return;
    box.textContent = text;
    box.hidden = false;
    box.classList.toggle('is-ok', !!ok);
  }

  async function submitForm(action, fields) {
    const response = await fetch(CONFIG.ajaxUrl + '?action=' + action, {
      method: 'POST',
      headers: { 'Content-Type': 'application/json', 'X-WP-Nonce': CONFIG.nonce || '' },
      body: JSON.stringify(fields),
    });
    const payload = await response.json().catch(() => null);
    if (!payload) throw new Error('پاسخ سرور قابل خواندن نبود.');
    if (!payload.success) throw new Error((payload.data && payload.data.message) || 'خطایی رخ داد.');
    return payload.data || {};
  }

  function wireForm(formId, action, fieldNames) {
    const form = $(formId);
    if (!form) return;
    form.addEventListener('submit', async (event) => {
      event.preventDefault();
      const submitBtn = form.querySelector('.gate__submit');
      submitBtn.disabled = true;
      try {
        const fields = {};
        fieldNames.forEach((name) => { fields[name] = form.elements[name].value; });
        const data = await submitForm(action, fields);
        showMessage(data.message || 'انجام شد.', true);
        // reload so PHP re-evaluates GC_License::current_user_allowed() and
        // renders either the real app or the "no license yet" gate
        setTimeout(() => window.location.reload(), 900);
      } catch (error) {
        showMessage(error.message, false);
        submitBtn.disabled = false;
      }
    });
  }

  function wireTabs() {
    const tabs = document.querySelectorAll('.gate__tab');
    if (!tabs.length) return;
    tabs.forEach((tab) => {
      tab.addEventListener('click', () => {
        tabs.forEach((t) => t.classList.toggle('is-active', t === tab));
        const target = tab.dataset.tab;
        document.querySelectorAll('.gate__form').forEach((form) => {
          form.hidden = form.dataset.form !== target;
        });
        const message = $('goldcrawlerAuthMessage');
        if (message) message.hidden = true;
      });
    });
  }

  document.addEventListener('DOMContentLoaded', () => {
    wireTabs();
    wireForm('goldcrawlerLoginForm', 'goldcrawler_login', ['username', 'password']);
    wireForm('goldcrawlerRegisterForm', 'goldcrawler_register', ['username', 'email', 'password']);
  });
})();
