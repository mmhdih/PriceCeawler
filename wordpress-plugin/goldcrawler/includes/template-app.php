<?php
/**
 * Shortcode markup for [gold_crawler]. Included (not required) by
 * GC_Shortcode::render() with $atts already validated; kept as a plain
 * template file rather than a giant PHP string for readability.
 */
if (!defined('ABSPATH')) { exit; }
?>
<div id="goldcrawler-app" class="goldcrawler-app" data-theme="light" dir="rtl">
<header class="topbar">
  <div class="brand">
    <span class="brand__mark" aria-hidden="true">
      <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
        <path d="M3 17l5-6 4 4 6-8"/><path d="M21 7h-4"/><path d="M21 7v4"/>
      </svg>
    </span>
    <span class="brand__text">
      <strong>کراولر قیمت</strong>
      <small>گزارش روزانه بازار از TGJU</small>
    </span>
  </div>

  <div class="topbar__meta">
    <span class="pill" id="todayPill" title="تاریخ امروز">—</span>
    <button class="icon-btn" id="themeBtn" type="button" title="تغییر پوسته" aria-label="تغییر پوسته">
      <svg class="icon-sun" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round"><circle cx="12" cy="12" r="4"/><path d="M12 2v2M12 20v2M2 12h2M20 12h2M4.9 4.9l1.4 1.4M17.7 17.7l1.4 1.4M19.1 4.9l-1.4 1.4M6.3 17.7l-1.4 1.4"/></svg>
      <svg class="icon-moon" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M21 12.8A9 9 0 1 1 11.2 3a7 7 0 0 0 9.8 9.8z"/></svg>
    </button>
    <span class="pill pill--ghost" id="versionPill">v—</span>
  </div>
</header>

<main class="layout">

  <!-- ───────────────── کنترل‌ها ───────────────── -->
  <aside class="panel sidebar" aria-label="تنظیمات گزارش">

    <section class="block">
      <div class="block__head">
        <h2>نمادها</h2>
        <span class="counter" id="symbolCount">۰</span>
      </div>

      <div class="search">
        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round"><circle cx="11" cy="11" r="7"/><path d="M20 20l-3.5-3.5"/></svg>
        <input type="search" id="symbolSearch" placeholder="جست‌وجوی نماد… (مثلاً طلا، دلار)" autocomplete="off">
      </div>

      <div class="chips chips--filters" id="groupFilters" role="tablist"></div>
      <div class="symbol-list" id="symbolList"></div>

      <details class="custom-symbol">
        <summary>افزودن نماد دلخواه از TGJU</summary>
        <div class="custom-symbol__body">
          <p class="hint">شناسه نماد را از آدرس صفحه TGJU بردارید: <code>tgju.org/profile/<b>geram18</b></code></p>
          <input type="text" id="customKey" placeholder="شناسه انگلیسی (مثلاً sekee)" autocomplete="off">
          <input type="text" id="customName" placeholder="نام نمایشی (اختیاری)" autocomplete="off">
          <div class="row">
            <select id="customCurrency" aria-label="واحد نماد">
              <option value="IRR">ریالی (نمایش به تومان)</option>
              <option value="USD">دلاری</option>
            </select>
            <button class="btn btn--ghost" id="addSymbolBtn" type="button">افزودن</button>
          </div>
        </div>
      </details>
    </section>

    <section class="block">
      <div class="block__head"><h2>بازه زمانی</h2></div>
      <div class="chips" id="rangePresets"></div>
      <div class="date-grid">
        <label class="field">
          <span>از تاریخ</span>
          <input type="text" id="startDate" inputmode="numeric" placeholder="۱۴۰۴/۰۱/۰۱" dir="ltr">
        </label>
        <label class="field">
          <span>تا تاریخ</span>
          <input type="text" id="endDate" inputmode="numeric" placeholder="۱۴۰۴/۱۲/۲۹" dir="ltr">
        </label>
      </div>
      <p class="field-error" id="dateError" hidden></p>
    </section>

    <section class="block">
      <div class="block__head"><h2>گزینه‌ها</h2></div>
      <label class="switch">
        <input type="checkbox" id="fillGaps" checked>
        <span class="switch__track"><span class="switch__thumb"></span></span>
        <span class="switch__label">
          پرکردن روزهای بدون معامله
          <small>تعطیلات با قیمت آخرین روز کاری پر می‌شود.</small>
        </span>
      </label>
      <label class="switch">
        <input type="checkbox" id="autoCrawl" checked>
        <span class="switch__track"><span class="switch__thumb"></span></span>
        <span class="switch__label">
          کراول خودکار روزانه
          <small>هر روز در اولین اجرا، آرشیو به‌روز می‌شود.</small>
        </span>
      </label>
    </section>

    <div class="sidebar__actions">
      <button class="btn btn--primary" id="fetchBtn" type="button">
        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M21 12a9 9 0 1 1-3-6.7"/><path d="M21 3v6h-6"/></svg>
        دریافت داده‌ها
      </button>
      <button class="btn btn--ghost" id="refreshBtn" type="button" title="نادیده‌گرفتن حافظه موقت و دریافت دوباره">دریافت تازه</button>
    </div>
  </aside>

  <!-- ───────────────── نتایج ───────────────── -->
  <section class="content">

    <div class="empty" id="emptyState">
      <div class="empty__art" aria-hidden="true">
        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round">
          <path d="M3 3v16a2 2 0 0 0 2 2h16"/><path d="M7 15l3.5-4.5 3 3L20 6"/>
        </svg>
      </div>
      <h2>گزارشی ساخته نشده است</h2>
      <p>نمادها و بازه زمانی را از پنل کناری انتخاب کنید و روی «دریافت داده‌ها» بزنید.</p>
    </div>

    <div id="results" hidden>
      <div class="stat-grid" id="statGrid"></div>

      <div class="panel card">
        <div class="card__head">
          <div>
            <h2>نمودار قیمت</h2>
            <p class="card__sub" id="chartSub">—</p>
          </div>
          <div class="chips chips--compact" id="chartModes"></div>
        </div>
        <div class="chart-wrap">
          <svg id="chart" role="img" aria-label="نمودار روند قیمت"></svg>
          <div class="chart-tip" id="chartTip" hidden></div>
        </div>
        <div class="legend" id="chartLegend"></div>
      </div>

      <div class="panel card">
        <div class="card__head">
          <div>
            <h2>جدول داده‌ها</h2>
            <p class="card__sub" id="tableSub">—</p>
          </div>
          <div class="export">
            <button class="btn btn--soft" data-format="xlsx" type="button">خروجی اکسل</button>
            <button class="btn btn--soft" data-format="csv" type="button">CSV</button>
            <button class="btn btn--soft" data-format="json" type="button">JSON</button>
          </div>
        </div>
        <div class="chips chips--tabs" id="tableTabs" role="tablist"></div>
        <div class="table-wrap">
          <table id="dataTable">
            <thead></thead>
            <tbody></tbody>
          </table>
        </div>
        <div class="table-foot" id="tableFoot"></div>
      </div>
    </div>

    <div class="panel card" id="archiveCard">
      <div class="card__head">
        <div>
          <h2>آرشیو روزانه</h2>
          <p class="card__sub" id="archiveSub">داده‌های دریافت‌شده روی هاست سایت ذخیره می‌شوند.</p>
        </div>
        <button class="btn btn--soft" id="crawlBtn" type="button">کراول همین حالا</button>
      </div>
      <div class="archive" id="archiveList"></div>
    </div>
  </section>
</main>

<div class="toasts" id="toasts" aria-live="polite"></div>

<div class="overlay" id="overlay" hidden>
  <div class="overlay__box">
    <div class="spinner" aria-hidden="true"></div>
    <p id="overlayText">در حال دریافت داده‌ها…</p>
  </div>
</div>
</div>
