(function () {
  'use strict';
  function bootClientLevelFeature() {
  var root = document.getElementById('icl-client');
  if (!root) return;
  var apiBase = '/console/v1/client_level';
  var detail = {};
  var loaded = { referrals: false, benefits: false, withdrawals: false };
  var copyResetTimer = 0;
  var clientMessageTimer = 0;
  var allocationSubmitting = false;
  var methodSubmitting = false;
  var withdrawMethodsLoaded = false;
  var withdrawSubmitting = false;

  function headers(withBody) {
    var result = { Accept: 'application/json', 'X-Requested-With': 'XMLHttpRequest' };
    var jwt = window.localStorage ? window.localStorage.getItem('jwt') : '';
    if (jwt) result.Authorization = 'Bearer ' + jwt;
    if (withBody) result['Content-Type'] = 'application/json';
    return result;
  }
  function request(path, options) {
    options = options || {};
    options.credentials = 'same-origin';
    options.headers = headers(!!options.body);
    return window.fetch(apiBase + path, options).then(function (response) {
      return response.text().then(function (text) {
        var payload;
        try { payload = JSON.parse(text); } catch (error) {
          var writeResponseUnknown = options.method && String(options.method).toUpperCase() !== 'GET';
          throw new Error(writeResponseUnknown ? '未能确认操作结果，请刷新页面核对。' : '服务暂时异常，请稍后重试。');
        }
        if (!payload || Number(payload.status) !== 200) throw new Error(payload && payload.msg ? payload.msg : '请求失败');
        return payload.data || {};
      });
    }, function () {
      var isWrite = options.method && String(options.method).toUpperCase() !== 'GET';
      throw new Error(isWrite ? '网络连接中断，未能确认操作结果，请刷新页面核对。' : '网络连接中断，请稍后重试。');
    });
  }
  function el(id) { return document.getElementById(id); }
  function node(tag, className, text) { var n = document.createElement(tag); if (className) n.className = className; if (typeof text !== 'undefined') n.textContent = text; return n; }
  function amount(value) { var n = Number(value || 0); return Number.isFinite(n) ? n.toFixed(2) : '0.00'; }
  function percent(value) { return amount(value).replace(/\.00$/, '') + '%'; }
  function date(value) { return Number(value) > 0 ? new Date(Number(value) * 1000).toLocaleString() : '-'; }
  function message(text, success) {
    var box = el('icl-client-message');
    if (clientMessageTimer) window.clearTimeout(clientMessageTimer);
    clientMessageTimer = 0;
    box.textContent = text;
    box.className = 'icl-client-message ' + (success ? 'is-success' : 'is-error');
    box.setAttribute('role', success ? 'status' : 'alert');
    box.setAttribute('title', '点击关闭');
    box.hidden = false;
    if (!success) clientMessageTimer = window.setTimeout(function () { box.hidden = true; }, 12000);
  }
  el('icl-client-message').addEventListener('click', function () { this.hidden = true; });
  function setBusy(button, busy, busyText) {
    if (!button) return;
    if (busy) {
      if (!button.getAttribute('data-idle-text')) button.setAttribute('data-idle-text', button.textContent);
      button.disabled = true;
      button.classList.add('is-busy');
      button.textContent = busyText || '处理中…';
      return;
    }
    button.disabled = false;
    button.classList.remove('is-busy');
    button.textContent = button.getAttribute('data-idle-text') || button.textContent;
  }
  function allocationFeedback(text, state) {
    var box = el('icl-allocation-feedback');
    box.textContent = text || '';
    box.className = 'icl-form-feedback' + (state ? ' is-' + state : '');
    box.setAttribute('role', state === 'error' ? 'alert' : 'status');
    box.hidden = !text;
  }
  function withdrawFeedback(text, state) {
    var box = el('icl-withdraw-feedback');
    box.textContent = text || '';
    box.className = 'icl-form-feedback' + (state ? ' is-' + state : '');
    box.setAttribute('role', state === 'error' ? 'alert' : 'status');
    box.hidden = !text;
  }
  function idempotency(prefix) {
    var bytes = new Uint8Array(12);
    if (window.crypto && window.crypto.getRandomValues) window.crypto.getRandomValues(bytes);
    else for (var i = 0; i < bytes.length; i++) bytes[i] = Math.floor(Math.random() * 256);
    return prefix + '_' + Array.prototype.map.call(bytes, function (v) { return v.toString(16).padStart(2, '0'); }).join('');
  }
  function cell(label, value) { var td = node('td', '', value); td.setAttribute('data-label', label); return td; }
  function emptyRow(target, colspan, text) { var tr = node('tr'); var td = node('td', 'icl-table-empty', text); td.colSpan = colspan; tr.appendChild(td); target.appendChild(tr); }
  function statusBadge(value) {
    var labels = { pending: '观察期', available: '可分配', approved: '已审核', paid: '已打款', rejected: '已驳回', cancelled: '已取消' };
    return node('span', 'icl-status is-' + value, labels[value] || value || '-');
  }
  function withdrawalStatusBadge(item) {
    if (item && item.status === 'pending') {
      return node(
        'span',
        'icl-status is-pending',
        Number(item.official_withdraw_id || 0) > 0 ? '待审核' : '提现冻结期'
      );
    }
    return statusBadge(item ? item.status : '');
  }

  root.querySelectorAll('[data-client-tab]').forEach(function (button) {
    button.addEventListener('click', function () {
      var tab = button.getAttribute('data-client-tab');
      root.querySelectorAll('[data-client-tab]').forEach(function (b) { b.classList.toggle('is-active', b === button); });
      root.querySelectorAll('[data-client-panel]').forEach(function (panel) { panel.classList.toggle('is-active', panel.getAttribute('data-client-panel') === tab); });
      if (tab === 'referrals' && !loaded.referrals) loadReferrals();
      if (tab === 'benefits' && !loaded.benefits) loadBenefits();
      if (tab === 'withdrawals' && !loaded.withdrawals) loadWithdrawals();
    });
  });

  function renderDetail(data) {
    detail = data || {};
    var axes = detail.level_axes || {};
    root.removeAttribute('v-cloak');
    el('icl-current-level').textContent = detail.level_name || '普通会员';
    el('icl-current-discount').textContent = percent(detail.discount_percent || 0);
    el('icl-lock-state').textContent = Number(detail.manual_lock) === 1 ? '专属等级' : '自动升级中';
    el('icl-own-spend').textContent = amount(detail.own_net_amount);
    el('icl-referral-spend').textContent = amount(detail.referral_net_amount);
    el('icl-contribution').textContent = amount(detail.contribution_amount);
    var modeLabel = axes.mode === 'both' ? '两线取高' : (axes.mode === 'own' ? '本人消费' : (axes.mode === 'referral' ? '推广贡献' : '等级保留'));
    el('icl-effective').textContent = modeLabel;
    el('icl-level-formula').textContent = axes.formula || '本人消费与推广贡献分别达标，取较高等级';
    el('icl-progress-current').textContent = axes.formula || modeLabel;
    var next = detail.next_level;
    var ratio = Number(detail.axis_progress_percent || 0);
    if (!next) ratio = 100;
    el('icl-progress-bar').style.width = ratio.toFixed(2) + '%';
    var gaps = [];
    if (next && Number(axes.own_spend_enabled) === 1) gaps.push('消费还差 ' + amount(axes.own_next_gap));
    if (next && Number(axes.referral_contribution_enabled) === 1) gaps.push('推广贡献还差 ' + amount(axes.referral_next_gap));
    el('icl-progress-next').textContent = next ? ('距离 ' + next.name + '：' + gaps.join('，')) : '已达最高启用等级';
    el('icl-next-level-name').textContent = next ? next.name : '已达最高等级';
    el('icl-next-level-gap').textContent = next ? gaps.join('，') : '继续积累仍可获得推广权益';
    el('icl-invite-code').textContent = detail.invite_code || '-';
    el('icl-invite-link').value = detail.invite_code ? window.location.origin + '/client-level/invite/' + encodeURIComponent(detail.invite_code) : '';
    renderBalances(); renderPolicy(); renderWithdrawalEligibility(); renderLevels(detail.levels || []);
  }
  function renderBalances() {
    el('icl-benefit-pending').textContent = amount(detail.benefit_pending_amount);
    el('icl-benefit-unallocated').textContent = amount(detail.benefit_unallocated_amount);
    el('icl-benefit-withdrawable').textContent = amount(detail.withdrawable_amount);
    el('icl-benefit-debt').textContent = amount(detail.benefit_debt_amount);
    renderAllocationEligibility();
  }
  function renderAllocationEligibility() {
    var available = Math.max(0, Number(detail.benefit_unallocated_amount || 0));
    var target = el('icl-allocation-target').value;
    var input = el('icl-allocation-amount');
    var button = el('icl-allocation-submit');
    input.min = '0.01';
    input.max = amount(available);
    input.disabled = allocationSubmitting || available <= 0;
    input.placeholder = available > 0 ? ('0.01 - ' + amount(available)) : '当前无可分配权益';
    button.disabled = allocationSubmitting || available <= 0;
    button.textContent = allocationSubmitting ? '正在分配…' : '确认分配';
    var preview = el('icl-allocation-preview');
    if (available <= 0) {
      preview.textContent = '当前待分配权益为 0.00 元。推广权益通过退款观察期后，才会进入待分配余额。';
      preview.className = 'icl-callout is-blocked';
      return;
    }
    var p = detail.referral_policy || {};
    preview.textContent = target === 'contribution'
      ? '当前最多可分配 ' + amount(available) + ' 元；将按 ' + percent(p.contribution_rate || 100) + ' 换算并永久锁定为等级贡献。'
      : '当前最多可分配 ' + amount(available) + ' 元；转入后可提交提现申请。';
    preview.className = 'icl-callout is-ready';
  }
  function renderPolicy() {
    var p = detail.referral_policy || {};
    el('icl-policy-reward').textContent = percent(p.reward_rate || 0);
    el('icl-policy-contribution').textContent = percent(p.contribution_rate || 100);
    el('icl-policy-days').textContent = String(Number(p.observation_days || 14)) + ' 天';
    el('icl-policy-review').textContent = String(Number(p.withdrawal_review_days || 7)) + ' 天';
    el('icl-policy-min').textContent = amount(p.min_withdraw);
  }
  function withdrawalState() {
    var p = detail.referral_policy || {};
    var available = Math.max(0, Number(detail.withdrawable_amount || 0));
    var debt = Math.max(0, Number(detail.benefit_debt_amount || 0));
    var minimum = Math.max(0.01, Number(p.min_withdraw || 0));
    var observationDays = Number(p.observation_days || 14);
    var reviewDays = Number(p.withdrawal_review_days || 7);
    if (debt > 0) {
      return { allowed: false, available: available, minimum: minimum, text: '当前有 ' + amount(debt) + ' 元退款待抵扣，结清前暂时不能申请提现。' };
    }
    if (available <= 0) {
      return { allowed: false, available: available, minimum: minimum, text: '当前可提现余额为 0.00 元，最低提现金额为 ' + amount(minimum) + ' 元，暂时无法申请。推广权益需先通过 ' + String(observationDays) + ' 天退款观察期，并转入可提现余额。' };
    }
    if (available < minimum) {
      return { allowed: false, available: available, minimum: minimum, text: '当前可提现余额为 ' + amount(available) + ' 元，距离最低提现金额还差 ' + amount(minimum - available) + ' 元。' };
    }
    if (!withdrawMethodsLoaded) {
      return { allowed: false, available: available, minimum: minimum, text: '余额已满足提现条件，正在核对收款方式…' };
    }
    if (!el('icl-withdraw-method').value) {
      return { allowed: false, available: available, minimum: minimum, text: '余额已满足提现条件，请先在左侧添加收款方式。' };
    }
    return { allowed: true, available: available, minimum: minimum, text: '当前最多可提现 ' + amount(available) + ' 元，单笔最低 ' + amount(minimum) + ' 元；提交后将冻结至少 ' + String(reviewDays) + ' 天，审核前可以取消。' };
  }
  function renderWithdrawalEligibility() {
    var state = withdrawalState();
    var input = el('icl-withdraw-amount');
    var button = el('icl-withdraw-submit');
    input.min = amount(state.minimum);
    input.max = amount(state.available);
    input.disabled = withdrawSubmitting || !state.allowed;
    input.placeholder = state.allowed ? (amount(state.minimum) + ' - ' + amount(state.available)) : '当前不可提现';
    button.disabled = withdrawSubmitting || !state.allowed;
    button.textContent = withdrawSubmitting ? '正在提交…' : '提交提现申请';
    var rule = el('icl-withdraw-rule');
    rule.textContent = state.text;
    rule.className = 'icl-callout ' + (state.allowed ? 'is-ready' : 'is-blocked');
  }
  function renderLevels(levels) {
    var wrap = el('icl-level-list'); wrap.textContent = '';
    levels.forEach(function (level) {
      var card = node('article', 'icl-client-level-card' + (Number(level.id) === Number(detail.level_id) ? ' is-current' : ''));
      card.style.setProperty('--level-color', level.background_color || '#4566d8');
      var header = node('header'); header.appendChild(node('h3', '', level.name || '会员等级')); header.appendChild(node('b', '', '减免 ' + percent(level.discount_percent)));
      var axes = detail.level_axes || {};
      var thresholds = [];
      if (Number(axes.own_spend_enabled) === 1) thresholds.push('消费 ' + amount(level.amount));
      if (Number(axes.referral_contribution_enabled) === 1) thresholds.push('推广贡献 ' + amount(level.referral_level_amount));
      card.appendChild(header); card.appendChild(node('p', '', (thresholds.length ? thresholds.join(' · ') : '当前不新增等级') + ' · 商品折扣 ' + ((level.discount_list || []).length) + ' 项'));
      wrap.appendChild(card);
    });
    if (!levels.length) wrap.appendChild(node('p', 'icl-muted', '暂无启用等级'));
  }
  function loadDetail(silent) {
    return request('').then(function (data) { renderDetail(data); return true; }).catch(function (e) {
      root.removeAttribute('v-cloak');
      if (!silent) message(e.message, false);
      return false;
    });
  }

  function loadReferrals(silent) {
    return request('/referrals?limit=100').then(function (data) {
      loaded.referrals = true; var rows = el('icl-referral-rows'); rows.textContent = '';
      var summary = data.summary || {};
      renderReferralDashboard(data.dashboard || {}, summary);
      el('icl-referral-total').textContent = String(Number(summary.total_clients || 0));
      el('icl-referral-paying').textContent = String(Number(summary.paying_clients || 0));
      el('icl-referral-conversion').textContent = percent(summary.conversion_rate || 0);
      el('icl-referral-orders').textContent = String(Number(summary.paid_order_count || 0));
      el('icl-referral-net').textContent = amount(summary.net_amount);
      el('icl-referral-refunds').textContent = amount(summary.refund_amount);
      (data.list || []).forEach(function (item) {
        var tr = node('tr');
        tr.appendChild(cell('客户', item.display_name || ('客户 #' + item.invitee_client_id)));
        tr.appendChild(cell('绑定时间', date(item.create_time)));
        tr.appendChild(cell('贡献开始', item.inherit_history ? '继承历史消费' : date(item.contribution_start_time)));
        tr.appendChild(cell('有效订单', String(Number(item.paid_order_count || 0))));
        tr.appendChild(cell('支付金额', amount(item.gross_paid_amount)));
        tr.appendChild(cell('退款金额', amount(item.refund_amount)));
        tr.appendChild(cell('客户净消费', amount(item.net_amount)));
        tr.appendChild(cell('最近支付', date(item.last_paid_time)));
        rows.appendChild(tr);
      });
      el('icl-referral-count').textContent = String(Number(data.count || 0)) + ' 位客户';
      if (!data.list || !data.list.length) emptyRow(rows, 8, '还没有直属推广客户');
      return true;
    }).catch(function (e) { if (!silent) message(e.message, false); return false; });
  }

  function renderReferralDashboard(dashboard, summary) {
    el('icl-referral-month-reward').textContent = amount(dashboard.month_reward);
    el('icl-referral-today-reward').textContent = amount(dashboard.today_reward);
    el('icl-referral-dashboard-clients').textContent = String(Number(dashboard.total_clients || summary.total_clients || 0));
    el('icl-referral-total-reward').textContent = amount(dashboard.total_reward);
    var mix = dashboard.order_mix || {};
    var newPercent = Math.min(100, Math.max(0, Number(mix.new_percent || 0)));
    el('icl-order-mix-ring').style.setProperty('--icl-new-percent', newPercent.toFixed(2) + '%');
    el('icl-order-mix-percent').textContent = percent(newPercent);
    el('icl-order-new-count').textContent = String(Number(mix.new || 0));
    el('icl-order-renew-count').textContent = String(Number(mix.renew || 0));
    el('icl-order-other-count').textContent = String(Number(mix.other || 0));
    var trend = Array.isArray(dashboard.daily_net_spend) ? dashboard.daily_net_spend : [];
    var trendNode = el('icl-referral-trend');
    trendNode.textContent = '';
    var maximum = trend.reduce(function (max, item) { return Math.max(max, Number(item.net_amount || 0)); }, 0);
    trend.forEach(function (item) {
      var value = Math.max(0, Number(item.net_amount || 0));
      var column = node('div', 'icl-trend-column');
      var plot = node('div', 'icl-trend-plot');
      var bar = node('span', 'icl-trend-bar');
      bar.style.height = (maximum > 0 ? Math.max(4, value / maximum * 92) : 2).toFixed(2) + 'px';
      bar.title = (item.date || '-') + ' · ' + amount(value);
      plot.appendChild(bar);
      column.appendChild(node('strong', '', amount(value)));
      column.appendChild(plot);
      column.appendChild(node('small', '', item.date || '-'));
      trendNode.appendChild(column);
    });
    if (!trend.length) trendNode.appendChild(node('p', 'icl-muted', '暂无趋势数据'));
  }

  function loadBenefits(silent) {
    return request('/accruals?limit=100').then(function (data) {
      loaded.benefits = true; var rows = el('icl-accrual-rows'); rows.textContent = '';
      (data.list || []).forEach(function (item) {
        var tr = node('tr'); tr.appendChild(cell('订单', '#' + item.source_order_id)); tr.appendChild(cell('客户', item.invitee_display)); tr.appendChild(cell('符合条件的消费', amount(item.base_net_amount))); tr.appendChild(cell('返利比例', percent(item.rate_percent))); tr.appendChild(cell('推广权益', amount(item.net_entitlement))); var td = cell('状态', ''); td.appendChild(statusBadge(item.status)); tr.appendChild(td); rows.appendChild(tr);
      });
      if (!data.list || !data.list.length) emptyRow(rows, 6, '暂无推广返利明细');
      return true;
    }).catch(function (e) { if (!silent) message(e.message, false); return false; });
  }

  function loadMethods() {
    return request('/withdraw/methods').then(function (data) {
      var select = el('icl-withdraw-method'); select.textContent = '';
      (data.list || []).forEach(function (item) { var option = node('option', '', item.type + ' · ' + item.account_mask + ' · ' + item.name_mask); option.value = item.id; select.appendChild(option); });
      if (!data.list || !data.list.length) { var empty = node('option', '', '请先添加收款方式'); empty.value = ''; select.appendChild(empty); }
      withdrawMethodsLoaded = true;
      renderWithdrawalEligibility();
    });
  }
  function loadWithdrawRows() {
    return request('/withdrawals?limit=100').then(function (data) {
      var rows = el('icl-withdraw-rows'); rows.textContent = '';
      (data.list || []).forEach(function (item) {
        var tr = node('tr'); tr.appendChild(cell('申请编号', item.business_no)); tr.appendChild(cell('金额', amount(item.amount))); tr.appendChild(cell('收款方式', item.method_type + ' · ' + item.account_mask)); var s = cell('状态', ''); s.appendChild(withdrawalStatusBadge(item)); tr.appendChild(s); tr.appendChild(cell('申请时间', date(item.create_time))); var action = cell('操作', ''); if (item.status === 'pending' && Number(item.official_withdraw_id || 0) === 0) { var button = node('button', 'icl-secondary-btn', '取消'); button.type = 'button'; button.addEventListener('click', function () { cancelWithdraw(item.id); }); action.appendChild(button); } else action.textContent = '-'; tr.appendChild(action); rows.appendChild(tr);
      });
      if (!data.list || !data.list.length) emptyRow(rows, 6, '暂无提现记录');
    });
  }
  function loadWithdrawals() { return Promise.all([loadMethods(), loadWithdrawRows()]).then(function () { loaded.withdrawals = true; }).catch(function (e) { message(e.message, false); }); }
  function cancelWithdraw(id) {
    if (!window.confirm('确定取消这笔提现申请吗？冻结金额将返回可提现余额。')) return;
    var buttons = Array.prototype.slice.call(el('icl-withdraw-rows').querySelectorAll('button'));
    buttons.forEach(function (button) { button.disabled = true; });
    message('正在取消提现申请…', true);
    request('/withdrawal/' + id + '/cancel', { method: 'POST', body: JSON.stringify({}) }).then(function () {
      message('提现已取消', true);
      return Promise.all([loadDetail(true), loadWithdrawRows().then(function () { return true; }, function () { return false; })]).then(function (results) {
        if (!results[0] || !results[1]) message('提现已取消；页面刷新未完成，请刷新页面查看', true);
      });
    }).catch(function (e) {
      buttons.forEach(function (button) { button.disabled = false; });
      message(e.message, false);
    });
  }

  function legacyCopyInvite(value) {
    var input = el('icl-invite-link');
    input.focus();
    input.select();
    if (typeof input.setSelectionRange === 'function') input.setSelectionRange(0, value.length);
    try { return document.execCommand('copy') === true; } catch (error) { return false; }
  }
  function setCopyResult(success) {
    var button = el('icl-copy-invite');
    if (copyResetTimer) window.clearTimeout(copyResetTimer);
    button.classList.toggle('is-copied', success);
    button.classList.toggle('is-copy-error', !success);
    button.textContent = success ? '已复制 ✓' : '复制失败';
    message(success ? '推广链接已复制，可以直接发送给好友' : '复制失败，请长按或选中链接手动复制', success);
    copyResetTimer = window.setTimeout(function () {
      button.classList.remove('is-copied', 'is-copy-error');
      button.textContent = '复制链接';
    }, 3000);
  }
  el('icl-copy-invite').addEventListener('click', function () {
    var value = el('icl-invite-link').value;
    if (!value) { setCopyResult(false); return; }
    if (navigator.clipboard && navigator.clipboard.writeText && window.isSecureContext) {
      navigator.clipboard.writeText(value).then(function () {
        setCopyResult(true);
      }).catch(function () {
        setCopyResult(legacyCopyInvite(value));
      });
      return;
    }
    setCopyResult(legacyCopyInvite(value));
  });
  el('icl-refresh-referrals').addEventListener('click', function () {
    var button = this;
    setBusy(button, true, '刷新中…');
    loadReferrals(false).then(function (ok) {
      if (ok) message('推广数据已更新', true);
      setBusy(button, false);
    });
  });
  el('icl-allocation-target').addEventListener('change', function () {
    allocationFeedback('', '');
    renderAllocationEligibility();
  });
  el('icl-allocation-amount').addEventListener('input', function () { allocationFeedback('', ''); });
  el('icl-allocation-form').addEventListener('submit', function (event) {
    event.preventDefault(); var target = el('icl-allocation-target').value; var amountValue = el('icl-allocation-amount').value;
    if (allocationSubmitting) return;
    var available = Math.max(0, Number(detail.benefit_unallocated_amount || 0));
    var requested = Number(amountValue);
    if (available <= 0) { allocationFeedback('当前没有可分配的推广权益。', 'error'); return; }
    if (!Number.isFinite(requested) || requested <= 0) { allocationFeedback('请输入要分配的金额。', 'error'); return; }
    if (requested > available) { allocationFeedback('当前最多可分配 ' + amount(available) + ' 元，请修改金额。', 'error'); return; }
    var warning = target === 'contribution' ? '等级贡献确认后不能转回现金，确定继续吗？' : '确定把这部分权益转入可提现余额吗？';
    if (!window.confirm(warning)) return;
    allocationSubmitting = true;
    renderAllocationEligibility();
    allocationFeedback('正在分配推广权益，请勿重复操作。', 'loading');
    request('/benefit/allocate', { method: 'POST', body: JSON.stringify({ amount: amount(requested), target: target, business_no: idempotency('allocation') }) }).then(function () {
      var successText = target === 'contribution' ? '已纳入等级贡献' : '已转入可提现余额';
      el('icl-allocation-amount').value = '';
      message(successText, true);
      allocationFeedback(successText + '。', 'success');
      loaded.benefits = false;
      return Promise.all([loadDetail(true), loadBenefits(true)]).then(function (results) {
        allocationSubmitting = false;
        renderAllocationEligibility();
        allocationFeedback(results[0] && results[1] ? successText + '。' : successText + '，但页面刷新未完成，请刷新页面查看。', results[0] && results[1] ? 'success' : 'warning');
      });
    }).catch(function (e) {
      allocationSubmitting = false;
      renderAllocationEligibility();
      allocationFeedback(e.message, 'error');
      message(e.message, false);
    });
  });
  el('icl-method-form').addEventListener('submit', function (event) {
    event.preventDefault();
    if (methodSubmitting) return;
    var submitButton = event.currentTarget.querySelector('button[type="submit"]');
    methodSubmitting = true;
    setBusy(submitButton, true, '保存中…');
    request('/withdraw/method', { method: 'POST', body: JSON.stringify({ type: el('icl-method-type').value, account: el('icl-method-account').value, name: el('icl-method-name').value, is_default: el('icl-method-default').checked ? 1 : 0 }) }).then(function () {
      el('icl-method-account').value = '';
      el('icl-method-name').value = '';
      message('收款方式已保存', true);
      return loadMethods().then(function () {}, function () { message('收款方式已保存；列表刷新未完成，请刷新页面查看', true); });
    }).catch(function (e) { message(e.message, false); }).then(function () {
      methodSubmitting = false;
      setBusy(submitButton, false);
    });
  });
  el('icl-withdraw-amount').addEventListener('input', function () { withdrawFeedback('', ''); });
  el('icl-withdraw-method').addEventListener('change', function () { withdrawFeedback('', ''); renderWithdrawalEligibility(); });
  el('icl-withdraw-form').addEventListener('submit', function (event) {
    event.preventDefault();
    if (withdrawSubmitting) return;
    var state = withdrawalState();
    var amountValue = Number(el('icl-withdraw-amount').value);
    if (!state.allowed) { withdrawFeedback(state.text, 'error'); return; }
    if (!Number.isFinite(amountValue) || amountValue <= 0) { withdrawFeedback('请输入要提现的金额。', 'error'); return; }
    if (amountValue < state.minimum) { withdrawFeedback('单笔提现不能低于 ' + amount(state.minimum) + ' 元。', 'error'); return; }
    if (amountValue > state.available) { withdrawFeedback('当前最多可提现 ' + amount(state.available) + ' 元，请修改金额。', 'error'); return; }
    if (!el('icl-withdraw-method').value) { withdrawFeedback('请先添加并选择收款方式。', 'error'); return; }
    withdrawSubmitting = true;
    renderWithdrawalEligibility();
    withdrawFeedback('正在提交提现申请，请勿重复操作。', 'loading');
    request('/withdrawal', { method: 'POST', body: JSON.stringify({ amount: amount(amountValue), method_id: Number(el('icl-withdraw-method').value), request_key: idempotency('withdraw') }) }).then(function () {
      el('icl-withdraw-amount').value = '';
      message('提现申请已提交，请等待审核', true);
      withdrawFeedback('提现申请已提交，请等待审核。', 'success');
      return Promise.all([request('').then(renderDetail), loadWithdrawRows()]).then(function () {
        withdrawSubmitting = false;
        renderWithdrawalEligibility();
        withdrawFeedback('提现申请已提交，请等待审核。', 'success');
      }).catch(function () {
        withdrawSubmitting = false;
        renderWithdrawalEligibility();
        withdrawFeedback('申请已提交，但余额和记录刷新未完成，请刷新页面查看。', 'warning');
      });
    }).catch(function (e) {
      withdrawSubmitting = false;
      renderWithdrawalEligibility();
      withdrawFeedback(e.message, 'error');
      message(e.message, false);
    });
  });

  loadDetail();
  }

  // 插件模板位于 V10 官方 Vue 壳层内部。必须等壳层 mounted 后再绑定
  // 原生交互，否则顶栏/侧栏的首次异步渲染会把页签 class 还原。
  if (window.__iclClientShellMounted) {
    bootClientLevelFeature();
  } else {
    window.addEventListener('icl:client-shell-mounted', bootClientLevelFeature, { once: true });
  }
})();
