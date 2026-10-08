/*!
 * 介護タクシー 料金シミュレーター＆LINE申込 ウィジェット
 *
 * 使い方（WordPressならカスタムHTMLブロックに貼る）:
 *   <div id="kaigo-taxi-sim"></div>
 *   <script src="https://example.com/kaigo-sim/assets/widget.js" defer></script>
 *
 * script タグの属性（任意）:
 *   data-target  … 表示先のセレクタ（既定: #kaigo-taxi-sim）
 *   data-api     … api.php のURL（既定: このJSと同じ設置場所の api.php）
 *   data-no-css  … 付けると widget.css を読み込まない（サイト側でデザインする場合）
 */
(function () {
  'use strict';

  var script = document.currentScript;
  var base = script.src.replace(/assets\/widget\.js(\?.*)?$/, '');
  var API = script.getAttribute('data-api') || base + 'api.php';
  var TARGET = script.getAttribute('data-target') || '#kaigo-taxi-sim';

  if (!script.hasAttribute('data-no-css')) {
    var link = document.createElement('link');
    link.rel = 'stylesheet';
    link.href = base + 'assets/widget.css';
    document.head.appendChild(link);
  }

  function esc(s) {
    return String(s == null ? '' : s).replace(/[&<>"']/g, function (c) {
      return { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c];
    });
  }
  function yen(n) {
    return (n < 0 ? '−' : '') + Math.abs(n).toLocaleString('ja-JP') + '円';
  }
  function today() {
    var d = new Date();
    d.setMinutes(d.getMinutes() - d.getTimezoneOffset());
    return d.toISOString().slice(0, 10);
  }

  function api(action, body) {
    var opt = body
      ? { method: 'POST', headers: { 'Content-Type': 'application/json' }, body: JSON.stringify(body) }
      : { method: 'GET' };
    return fetch(API + '?action=' + action, opt)
      .then(function (r) { return r.json(); })
      .catch(function () { return { ok: false, error: '通信エラーが発生しました。電波状況をご確認ください。' }; })
      .then(function (res) {
        if (!res.ok) throw new Error(res.error || 'エラーが発生しました。');
        return res;
      });
  }

  function init() {
    var root = document.querySelector(TARGET);
    if (!root) return;
    root.classList.add('kts');
    root.innerHTML = '<p class="kts-loading">読み込み中…</p>';
    api('config')
      .then(function (res) { render(root, res.config); })
      .catch(function (e) { root.innerHTML = '<p class="kts-error">' + esc(e.message) + '</p>'; });
  }

  function render(root, cfg) {
    var optionsHtml = cfg.options.map(function (o) {
      return '<label class="kts-check"><input type="checkbox" name="options" value="' + esc(o.name) + '"> ' +
        esc(o.name) + (o.price > 0 ? '（+' + yen(o.price) + '）' : '（無料）') + '</label>';
    }).join('');

    root.innerHTML =
      (cfg.mock_distance ? '<p class="kts-mock">テストモード：距離はダミー値です（Google Maps未使用）</p>' : '') +

      // ---- 1. 料金シミュレーション ----
      '<form class="kts-step kts-quote-form" novalidate>' +
      '<h3 class="kts-title">料金シミュレーション</h3>' +
      field('お迎え先の住所', '<input type="text" name="origin" required maxlength="200" placeholder="例）東京都新宿区西新宿2-8-1" autocomplete="street-address">') +
      field('行き先の住所・施設名', '<input type="text" name="destination" required maxlength="200" placeholder="例）〇〇病院（住所が確実です）">') +
      (cfg.round_trip_enabled
        ? field('片道／往復',
          '<label class="kts-radio"><input type="radio" name="round_trip" value="0" checked> 片道</label>' +
          '<label class="kts-radio"><input type="radio" name="round_trip" value="1"> 往復</label>')
        : '') +
      (cfg.options.length ? field('オプション', optionsHtml) : '') +
      '<button type="submit" class="kts-btn">料金を計算する</button>' +
      '<p class="kts-error" hidden></p>' +
      '</form>' +

      '<div class="kts-result" hidden></div>' +

      // ---- 2. 申込情報 ----
      '<form class="kts-step kts-apply-form" novalidate hidden>' +
      '<h3 class="kts-title">お申し込み情報</h3>' +
      '<div class="kts-row">' +
      field('ご利用日', '<input type="date" name="date" required min="' + today() + '">') +
      field('お迎え時刻', '<input type="time" name="time" required step="300">') +
      '</div>' +
      field('お名前', '<input type="text" name="name" required maxlength="50" autocomplete="name">') +
      field('電話番号', '<input type="tel" name="phone" required maxlength="20" autocomplete="tel" placeholder="090-1234-5678">') +
      field('ご利用者の状態', select('condition', cfg.conditions, '選択してください')) +
      field('同乗者（付き添い）', select('companions', ['0', '1', '2', '3', '4', '5'], null, '名')) +
      field('お支払方法', cfg.payment_methods.map(function (p, i) {
        return '<label class="kts-radio"><input type="radio" name="payment" value="' + esc(p) + '"' + (i === 0 ? ' checked' : '') + '> ' + esc(p) + '</label>';
      }).join('')) +
      field('備考（任意）', '<textarea name="note" rows="3" maxlength="500" placeholder="階段の有無、病院の受付時間など"></textarea>') +
      '<input type="text" name="website" class="kts-hp" tabindex="-1" autocomplete="off" aria-hidden="true">' +
      '<label class="kts-check kts-agree"><input type="checkbox" name="agree" value="1"> ' +
      (cfg.privacy_url
        ? '<a href="' + esc(cfg.privacy_url) + '" target="_blank" rel="noopener">個人情報の取り扱い</a>に同意する'
        : '入力した情報を予約の対応に利用することに同意する') +
      '</label>' +
      '<p class="kts-note">※この時点ではまだ予約は確定していません。空き状況を確認のうえ、LINEでご連絡します。</p>' +
      '<button type="submit" class="kts-btn kts-btn-line">内容を確定してLINEで送る</button>' +
      '<p class="kts-error" hidden></p>' +
      '</form>' +

      '<div class="kts-done" hidden></div>';

    var quoteForm = root.querySelector('.kts-quote-form');
    var applyForm = root.querySelector('.kts-apply-form');
    var resultBox = root.querySelector('.kts-result');
    var doneBox = root.querySelector('.kts-done');
    var lastQuoteInput = null;

    function quoteInput() {
      return {
        origin: quoteForm.elements['origin'].value,
        destination: quoteForm.elements['destination'].value,
        round_trip: quoteForm.elements['round_trip'] ? quoteForm.elements['round_trip'].value === '1' : false,
        options: Array.prototype.filter.call(quoteForm.querySelectorAll('[name=options]'), function (c) { return c.checked; })
          .map(function (c) { return c.value; })
      };
    }

    // 計算後に条件を変えたら、古い料金のまま申し込めないようにする
    quoteForm.addEventListener('input', function () {
      if (!lastQuoteInput) return;
      lastQuoteInput = null;
      resultBox.hidden = true;
      applyForm.hidden = true;
    });

    quoteForm.addEventListener('submit', function (e) {
      e.preventDefault();
      var input = quoteInput();
      if (!input.origin.trim() || !input.destination.trim()) {
        return showError(quoteForm, 'お迎え先と行き先を入力してください。');
      }
      busy(quoteForm, true);
      api('quote', input)
        .then(function (res) {
          showError(quoteForm, '');
          lastQuoteInput = input;
          showResult(res.quote);
        })
        .catch(function (err) { showError(quoteForm, err.message); })
        .then(function () { busy(quoteForm, false); });
    });

    function showResult(q) {
      applyForm.hidden = true;
      if (q.out_of_range) {
        resultBox.innerHTML =
          '<p class="kts-out">片道 約' + q.distance_km + 'km のため、Webでの料金表示の対象外です。</p>' +
          '<p>お手数ですが、LINEまたはお電話でご相談ください。</p>' +
          (cfg.line_friend_url ? '<a class="kts-btn kts-btn-line" href="' + esc(cfg.line_friend_url) + '" target="_blank" rel="noopener">LINEで相談する</a>' : '');
        resultBox.hidden = false;
        return;
      }
      resultBox.innerHTML =
        '<p class="kts-meta">片道 約' + q.distance_km + 'km／車で約' + q.duration_min + '分' +
        '　<a href="' + esc(q.map_url) + '" target="_blank" rel="noopener">経路を地図で確認</a></p>' +
        '<table class="kts-table">' +
        q.items.map(function (it) {
          return '<tr><th>' + esc(it.label) + '</th><td>' + yen(it.amount) + '</td></tr>';
        }).join('') +
        '<tr class="kts-total"><th>概算料金</th><td>' + yen(q.total) + '</td></tr>' +
        '</table>' +
        (cfg.notice_text ? '<p class="kts-note">' + esc(cfg.notice_text) + '</p>' : '') +
        '<p class="kts-attr">経路・距離: Google</p>' +
        '<button type="button" class="kts-btn kts-to-apply">この内容で申し込む</button>';
      resultBox.hidden = false;
      resultBox.querySelector('.kts-to-apply').addEventListener('click', function () {
        applyForm.hidden = false;
        applyForm.scrollIntoView({ behavior: 'smooth', block: 'start' });
      });
    }

    applyForm.addEventListener('submit', function (e) {
      e.preventDefault();
      if (!lastQuoteInput) return showError(applyForm, '先に料金を計算してください。');
      var f = applyForm;
      var payment = f.querySelector('[name=payment]:checked');
      var data = Object.assign({}, lastQuoteInput, {
        date: f.elements['date'].value,
        time: f.elements['time'].value,
        name: f.elements['name'].value,
        phone: f.elements['phone'].value,
        condition: f.elements['condition'].value,
        companions: f.elements['companions'].value,
        payment: payment ? payment.value : '',
        note: f.elements['note'].value,
        website: f.elements['website'].value,
        agree: f.elements['agree'].checked
      });
      var missing = !data.date ? 'ご利用日' : !data.time ? 'お迎え時刻' : !data.name.trim() ? 'お名前'
        : !data.phone.trim() ? '電話番号' : !data.condition ? 'ご利用者の状態' : '';
      if (missing) return showError(f, missing + 'を入力してください。');
      if (!data.agree) return showError(f, '個人情報の取り扱いへの同意が必要です。');

      busy(f, true);
      api('apply', data)
        .then(function (res) { showDone(res); })
        .catch(function (err) { showError(f, err.message); busy(f, false); });
    });

    function showDone(res) {
      quoteForm.hidden = true;
      resultBox.hidden = true;
      applyForm.hidden = true;
      doneBox.innerHTML =
        '<h3 class="kts-title">あと少しで申込完了です</h3>' +
        '<p>申込番号：<strong>' + esc(res.id) + '</strong></p>' +
        (res.line_url
          ? '<p><strong>下のボタンからLINEを開き、入力済みのメッセージをそのまま送信してください。</strong>送信で申込完了となります。</p>' +
            '<a class="kts-btn kts-btn-line" href="' + esc(res.line_url) + '" target="_blank" rel="noopener">LINEを開いて送信する</a>' +
            '<p class="kts-note">うまく開かない場合は、<a href="' + esc(res.line_friend_url) + '" target="_blank" rel="noopener">友だち追加</a>のうえ、下の内容をコピーしてLINEで送ってください。</p>'
          : '<p>担当者よりご連絡いたします。</p>') +
        '<pre class="kts-message">' + esc(res.message) + '</pre>' +
        '<button type="button" class="kts-btn kts-btn-sub kts-copy">内容をコピー</button>' +
        '<p class="kts-note">※まだ予約は確定していません。空き状況を確認のうえ、担当者からLINEでご連絡します。</p>';
      doneBox.hidden = false;
      doneBox.querySelector('.kts-copy').addEventListener('click', function (e) {
        var btn = e.currentTarget;
        navigator.clipboard.writeText(res.message).then(function () { btn.textContent = 'コピーしました'; });
      });
      root.scrollIntoView({ behavior: 'smooth', block: 'start' });
    }
  }

  function field(label, control) {
    return '<div class="kts-field"><span class="kts-label">' + esc(label) + '</span><div class="kts-control">' + control + '</div></div>';
  }
  function select(name, values, placeholder, suffix) {
    return '<select name="' + name + '" required>' +
      (placeholder ? '<option value="">' + esc(placeholder) + '</option>' : '') +
      values.map(function (v) { return '<option value="' + esc(v) + '">' + esc(v) + (suffix || '') + '</option>'; }).join('') +
      '</select>';
  }
  function showError(form, msg) {
    var p = form.querySelector('.kts-error');
    p.textContent = msg;
    p.hidden = !msg;
  }
  function busy(form, on) {
    var btn = form.querySelector('button[type=submit]');
    btn.disabled = on;
    btn.classList.toggle('is-busy', on);
  }

  if (document.readyState === 'loading') document.addEventListener('DOMContentLoaded', init);
  else init();
})();
