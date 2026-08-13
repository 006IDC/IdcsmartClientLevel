(function () {
  'use strict';

  var root = document.getElementById('icl-admin');
  if (!root) return;

  var firstSegment = window.location.pathname.split('/').filter(Boolean)[0] || 'admin';
  var apiBase = '/' + firstSegment + '/v1/client_level';
  var messageTimer = 0;
  var state = { levels: [], clients: [], axisSettings: {}, productPage: 1, productLimit: 50, productCount: 0 };
  var nodes = {
    message: document.getElementById('icl-message'),
    stats: document.getElementById('icl-stats'),
    levels: document.getElementById('icl-levels'),
    clients: document.getElementById('icl-clients'),
    binds: document.getElementById('icl-binds'),
    products: document.getElementById('icl-products'),
    logs: document.getElementById('icl-logs'),
    levelDialog: document.getElementById('icl-level-dialog'),
    levelFeedback: document.getElementById('icl-level-feedback'),
    clientDialog: document.getElementById('icl-client-dialog')
  };
  document.getElementById('icl-official-withdrawals').href = '/' + firstSegment + '/plugin/idcsmart_withdraw/index.htm';

  function headers(withBody) {
    var result = {
      Accept: 'application/json',
      'X-Requested-With': 'XMLHttpRequest'
    };
    var jwt = window.localStorage ? window.localStorage.getItem('backJwt') : '';
    if (jwt) result.Authorization = 'Bearer ' + jwt;
    if (withBody) result['Content-Type'] = 'application/json';
    return result;
  }

  function request(path, options) {
    options = options || {};
    options.credentials = 'same-origin';
    options.headers = headers(!!options.body);
    var isWrite = options.method && String(options.method).toUpperCase() !== 'GET';
    return window.fetch(apiBase + path, options).catch(function () {
      throw new Error(isWrite ? '网络连接中断，未能确认操作结果，请刷新页面核对。' : '网络连接中断，请稍后重试。');
    }).then(function (response) {
      return response.text().then(function (body) {
        var payload;
        try {
          payload = JSON.parse(body);
        } catch (error) {
          throw new Error(isWrite ? '未能确认操作结果，请刷新页面核对。' : '服务暂时异常，请稍后重试。');
        }
        return payload;
      });
    }).then(function (payload) {
      if (!payload || payload.status !== 200) {
        throw new Error(payload && payload.msg ? payload.msg : '请求失败');
      }
      return payload.data || {};
    });
  }

  function make(tag, className, text) {
    var node = document.createElement(tag);
    if (className) node.className = className;
    if (typeof text !== 'undefined') node.textContent = text;
    return node;
  }

  function showMessage(text, success) {
    if (messageTimer) window.clearTimeout(messageTimer);
    messageTimer = 0;
    nodes.message.textContent = text;
    nodes.message.className = 'icl-message ' + (success ? 'is-success' : 'is-error');
    nodes.message.setAttribute('role', success ? 'status' : 'alert');
    nodes.message.setAttribute('title', '点击关闭');
    nodes.message.hidden = false;
    // 成功结果保留到管理员看见或下一次操作；错误也给予足够阅读时间。
    if (!success) {
      messageTimer = window.setTimeout(function () { nodes.message.hidden = true; }, 12000);
    }
  }

  nodes.message.addEventListener('click', function () { nodes.message.hidden = true; });

  function showLevelFeedback(text, stateName) {
    if (!nodes.levelFeedback) return;
    nodes.levelFeedback.textContent = text || '';
    nodes.levelFeedback.className = 'icl-form-feedback' + (stateName ? ' is-' + stateName : '');
    nodes.levelFeedback.hidden = !text;
  }

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

  function afterWriteRefresh(successText, refreshes) {
    return Promise.all(refreshes).then(function (results) {
      var refreshed = results.every(function (result) { return result !== false; });
      showMessage(refreshed ? successText : successText + '；页面刷新未完成，请刷新页面查看', true);
      return refreshed;
    });
  }

  function runReadAction(button, busyText, reader, successText) {
    setBusy(button, true, busyText);
    return reader().then(function (ok) {
      if (ok !== false) showMessage(successText, true);
      return ok;
    }).then(function (result) {
      setBusy(button, false);
      return result;
    });
  }

  function showDialogError(dialog, text) {
    var previous = dialog ? dialog.querySelector('.icl-dialog-feedback') : null;
    if (previous) previous.remove();
    if (!dialog) { showMessage(text, false); return; }
    var box = make('p', 'icl-form-feedback is-error icl-dialog-feedback', text);
    box.setAttribute('role', 'alert');
    var actions = dialog.querySelector('.icl-actions');
    if (actions) actions.parentNode.insertBefore(box, actions);
    else dialog.appendChild(box);
  }

  function money(value) {
    var number = Number(value || 0);
    return Number.isFinite(number) ? number.toFixed(2) : '0.00';
  }

  function axisMode(settings) {
    var own = Number((settings || {}).own_spend_level_enabled) === 1;
    var referral = Number((settings || {}).referral_contribution_level_enabled) === 1;
    return own && referral ? '消费 / 推广取高' : (own ? '仅本人消费' : (referral ? '仅推广贡献' : '暂不新增等级'));
  }

  function time(value) {
    return value ? new Date(Number(value) * 1000).toLocaleString() : '-';
  }

  function emptyRow(target, colspan, text) {
    var row = document.createElement('tr');
    var cell = make('td', 'icl-empty', text);
    cell.colSpan = colspan;
    row.appendChild(cell);
    target.appendChild(row);
  }

  root.querySelectorAll('[data-tab]').forEach(function (button) {
    button.addEventListener('click', function () {
      var tab = button.getAttribute('data-tab');
      root.querySelectorAll('[data-tab]').forEach(function (item) { item.classList.toggle('is-active', item === button); });
      root.querySelectorAll('[data-panel]').forEach(function (panel) { panel.classList.toggle('is-active', panel.getAttribute('data-panel') === tab); });
      if (tab === 'clients') loadClients();
      if (tab === 'referrals') loadBinds();
      if (tab === 'products') loadProducts(1);
      if (tab === 'settings') loadSettings();
      if (tab === 'logs') loadLogs();
    });
  });

  function loadDashboard(silent) {
    return request('/dashboard').then(function (data) {
      nodes.stats.textContent = '';
      [
        ['等级数量', data.levels],
        ['已分配用户', data.members],
        ['管理员固定等级', data.manual_members],
        ['已记账订单', data.ledger_orders],
        ['有效推广绑定', data.active_referrals],
        ['待处理提现', data.withdraw_pending]
      ].forEach(function (item) {
        var card = make('div', 'icl-stat');
        card.appendChild(make('span', '', item[0]));
        card.appendChild(make('strong', '', String(item[1] || 0)));
        nodes.stats.appendChild(card);
      });
      return true;
    }).catch(function (error) { if (!silent) showMessage(error.message, false); return false; });
  }

  function loadLevels(silent) {
    return request('?limit=100').then(function (data) {
      state.levels = data.list || [];
      nodes.levels.textContent = '';
      state.levels.forEach(function (level) {
        var card = make('article', 'icl-level-card');
        var name = make('div', 'icl-level-name');
        var dot = make('span', 'icl-level-dot');
        dot.style.backgroundColor = level.background_color || '#64748B';
        name.appendChild(dot);
        name.appendChild(make('h3', '', level.name));
        card.appendChild(name);
        card.appendChild(make('div', 'icl-level-discount', '减免 ' + money(level.discount_percent) + '%'));
        card.appendChild(make('div', 'icl-level-meta', '实付 ' + money(level.pay_percent) + '% · 消费门槛 ' + money(level.amount)));
        card.appendChild(make('div', 'icl-level-meta', '推广贡献门槛 ' + money(level.referral_level_amount)));
        card.appendChild(make('div', 'icl-level-meta', '用户 ' + (level.member_count || 0) + ' · ' + (Number(level.status) === 1 ? '已启用' : '已停用')));
        card.addEventListener('click', function () { editLevel(level.id); });
        nodes.levels.appendChild(card);
      });
      if (!state.levels.length) nodes.levels.appendChild(make('p', 'icl-empty', '暂无等级'));
      return true;
    }).catch(function (error) { if (!silent) showMessage(error.message, false); return false; });
  }

  function resetLevelForm() {
    document.getElementById('icl-dialog-title').textContent = '新增等级';
    document.getElementById('icl-level-id').value = '';
    document.getElementById('icl-level-name').value = '';
    document.getElementById('icl-level-amount').value = '0.00';
    document.getElementById('icl-level-referral-amount').value = '0.00';
    document.getElementById('icl-level-discount').value = '0.00';
    document.getElementById('icl-level-color').value = '#2F54EB';
    document.getElementById('icl-level-sort').value = '0';
    document.getElementById('icl-level-status').checked = true;
    document.getElementById('icl-level-discount-status').checked = true;
    document.getElementById('icl-level-notes').value = '';
    document.getElementById('icl-policy-reward-override').checked = false;
    document.getElementById('icl-policy-reward').value = '0.00';
    document.getElementById('icl-policy-contribution-override').checked = false;
    document.getElementById('icl-policy-contribution').value = '100.00';
    document.getElementById('icl-policy-min-override').checked = false;
    document.getElementById('icl-policy-min').value = '0.00';
    document.getElementById('icl-delete-level').hidden = true;
    showLevelFeedback('', '');
    updatePayPreview();
  }

  function openDialog(dialog) {
    if (typeof dialog.showModal === 'function') dialog.showModal();
    else dialog.setAttribute('open', 'open');
  }

  function closeDialog(dialog) {
    var feedback = dialog ? dialog.querySelector('.icl-dialog-feedback') : null;
    if (feedback) feedback.remove();
    if (typeof dialog.close === 'function') dialog.close();
    else dialog.removeAttribute('open');
  }

  function editLevel(id) {
    request('/' + id).then(function (data) {
      var level = data.level;
      document.getElementById('icl-dialog-title').textContent = '编辑等级';
      document.getElementById('icl-level-id').value = level.id;
      document.getElementById('icl-level-name').value = level.name;
      document.getElementById('icl-level-amount').value = money(level.amount);
      document.getElementById('icl-level-referral-amount').value = money(level.referral_level_amount || level.amount);
      document.getElementById('icl-level-discount').value = money(level.discount_percent);
      document.getElementById('icl-level-color').value = level.background_color || '#2F54EB';
      document.getElementById('icl-level-sort').value = level.sort || 0;
      document.getElementById('icl-level-status').checked = Number(level.status) === 1;
      document.getElementById('icl-level-discount-status').checked = Number(level.discount_status) === 1;
      document.getElementById('icl-level-notes').value = level.notes || '';
      document.getElementById('icl-delete-level').hidden = false;
      showLevelFeedback('', '');
      updatePayPreview();
      openDialog(nodes.levelDialog);
      request('/level/' + id + '/policy').then(function (policyData) {
        var policy = policyData.policy || {};
        document.getElementById('icl-level-referral-amount').value = money(typeof policy.referral_level_amount === 'undefined' ? level.amount : policy.referral_level_amount);
        document.getElementById('icl-policy-reward-override').checked = Number(policy.reward_rate_override) === 1;
        document.getElementById('icl-policy-reward').value = money(policy.reward_rate);
        document.getElementById('icl-policy-contribution-override').checked = Number(policy.contribution_rate_override) === 1;
        document.getElementById('icl-policy-contribution').value = money(typeof policy.contribution_rate === 'undefined' ? 100 : policy.contribution_rate);
        document.getElementById('icl-policy-min-override').checked = Number(policy.min_withdraw_override) === 1;
        document.getElementById('icl-policy-min').value = money(policy.min_withdraw);
      }).catch(function (error) { showLevelFeedback(error.message, 'error'); });
    }).catch(function (error) { showMessage(error.message, false); });
  }

  function levelPayload() {
    return {
      id: Number(document.getElementById('icl-level-id').value || 0),
      name: document.getElementById('icl-level-name').value,
      amount: document.getElementById('icl-level-amount').value,
      discount_percent: document.getElementById('icl-level-discount').value,
      background_color: document.getElementById('icl-level-color').value,
      sort: Number(document.getElementById('icl-level-sort').value || 0),
      status: document.getElementById('icl-level-status').checked ? 1 : 0,
      discount_status: document.getElementById('icl-level-discount-status').checked ? 1 : 0,
      notes: document.getElementById('icl-level-notes').value
    };
  }

  function levelPolicyPayload() {
    return {
      referral_level_amount: document.getElementById('icl-level-referral-amount').value,
      reward_rate_override: document.getElementById('icl-policy-reward-override').checked ? 1 : 0,
      reward_rate: document.getElementById('icl-policy-reward').value,
      contribution_rate_override: document.getElementById('icl-policy-contribution-override').checked ? 1 : 0,
      contribution_rate: document.getElementById('icl-policy-contribution').value,
      min_withdraw_override: document.getElementById('icl-policy-min-override').checked ? 1 : 0,
      min_withdraw: document.getElementById('icl-policy-min').value
    };
  }

  function updatePayPreview() {
    var discount = Math.min(100, Math.max(0, Number(document.getElementById('icl-level-discount').value || 0)));
    document.getElementById('icl-pay-preview').textContent = '用户实付比例：' + (100 - discount).toFixed(2) + '%';
  }

  document.getElementById('icl-create-level').addEventListener('click', function () { resetLevelForm(); openDialog(nodes.levelDialog); });
  document.getElementById('icl-close-dialog').addEventListener('click', function () { closeDialog(nodes.levelDialog); });
  document.getElementById('icl-level-discount').addEventListener('input', updatePayPreview);
  document.getElementById('icl-level-form').addEventListener('invalid', function () {
    showLevelFeedback('请补全或修正标记的等级信息', 'error');
  }, true);
  document.getElementById('icl-level-form').addEventListener('submit', function (event) {
    event.preventDefault();
    var submitButton = event.currentTarget.querySelector('button[type="submit"]');
    setBusy(submitButton, true, '保存中…');
    showLevelFeedback('正在保存等级…', 'pending');
    var payload = Object.assign(levelPayload(), levelPolicyPayload());
    var path = payload.id ? '/' + payload.id : '';
    request(path, { method: payload.id ? 'PUT' : 'POST', body: JSON.stringify(payload) }).then(function (saved) {
      var levelId = payload.id || Number(saved.id || 0);
      if (!levelId) throw new Error('等级保存成功但未返回等级ID');
      closeDialog(nodes.levelDialog);
      return Promise.all([loadLevels(true), loadDashboard(true)]);
    }).then(function (refreshed) {
      showMessage(refreshed[0] && refreshed[1] ? '等级已保存' : '等级已保存；列表刷新未完成，请刷新页面查看', true);
    }).catch(function (error) {
      showLevelFeedback(error.message, 'error');
      showMessage(error.message, false);
    }).then(function () { setBusy(submitButton, false); });
  });
  document.getElementById('icl-delete-level').addEventListener('click', function () {
    var deleteButton = this;
    var id = Number(document.getElementById('icl-level-id').value || 0);
    if (!id || !window.confirm('确定删除这个等级吗？仍有用户属于该等级时系统会拒绝删除。')) return;
    setBusy(deleteButton, true, '删除中…');
    request('/' + id, { method: 'DELETE' }).then(function () {
      closeDialog(nodes.levelDialog);
      return afterWriteRefresh('等级已删除', [loadLevels(true), loadDashboard(true)]);
    }).catch(function (error) { showMessage(error.message, false); }).then(function () { setBusy(deleteButton, false); });
  });

  function loadClients(silent) {
    var keywords = document.getElementById('icl-client-keywords').value.trim();
    request('/clients?limit=100&keywords=' + encodeURIComponent(keywords)).then(function (data) {
      state.clients = data.list || [];
      state.axisSettings = data.axis_settings || state.axisSettings || {};
      nodes.clients.textContent = '';
      state.clients.forEach(function (client) {
        var row = document.createElement('tr');
        var user = make('td');
        user.appendChild(make('strong', '', '#' + client.id + ' ' + (client.username || '')));
        user.appendChild(make('div', 'icl-level-meta', client.email || ((client.phone_code || '') + ' ' + (client.phone || ''))));
        row.appendChild(user);
        var level = make('td');
        var badge = make('span', 'icl-badge', client.level_name || '未分配');
        if (client.background_color) badge.style.color = client.background_color;
        level.appendChild(badge); row.appendChild(level);
        row.appendChild(make('td', '', money(client.own_net_amount)));
        row.appendChild(make('td', '', money(client.contribution_amount)));
        row.appendChild(make('td', '', axisMode(state.axisSettings)));
        row.appendChild(make('td', '', money(client.benefit_unallocated) + ' / ' + money(client.withdrawable)));
        row.appendChild(make('td', '', Number(client.manual_lock) === 1 ? '管理员固定' : '自动计算'));
        var action = make('td');
        var button = make('button', 'icl-btn', '调整'); button.type = 'button';
        button.addEventListener('click', function () { openClient(client); });
        action.appendChild(button); row.appendChild(action); nodes.clients.appendChild(row);
      });
      if (!state.clients.length) emptyRow(nodes.clients, 8, '没有匹配的用户');
      return true;
    }).catch(function (error) { if (!silent) showMessage(error.message, false); return false; });
  }

  function openClient(client) {
    document.getElementById('icl-client-id').value = client.id;
    document.getElementById('icl-client-label').textContent = '#' + client.id + ' ' + (client.username || '') + ' · 本人消费 ' + money(client.own_net_amount) + ' · 推广贡献 ' + money(client.contribution_amount) + ' · ' + axisMode(state.axisSettings);
    var select = document.getElementById('icl-client-level'); select.textContent = '';
    var automatic = make('option', '', '恢复自动计算'); automatic.value = '0'; select.appendChild(automatic);
    state.levels.forEach(function (level) { var option = make('option', '', level.name + '（减免 ' + money(level.discount_percent) + '%）'); option.value = level.id; select.appendChild(option); });
    select.value = String(client.level_id || 0);
    document.getElementById('icl-client-lock').checked = Number(client.manual_lock) === 1;
    document.getElementById('icl-client-expire').value = '';
    document.getElementById('icl-client-reason').value = '';
    openDialog(nodes.clientDialog);
  }

  document.getElementById('icl-close-client-dialog').addEventListener('click', function () { closeDialog(nodes.clientDialog); });
  document.getElementById('icl-client-level').addEventListener('change', function () {
    document.getElementById('icl-client-lock').checked = Number(this.value) > 0;
  });
  document.getElementById('icl-client-form').addEventListener('submit', function (event) {
    event.preventDefault();
    var submitButton = event.currentTarget.querySelector('button[type="submit"]');
    var levelId = Number(document.getElementById('icl-client-level').value || 0);
    var lock = levelId > 0 && document.getElementById('icl-client-lock').checked;
    var reason = document.getElementById('icl-client-reason').value.trim();
    if (!reason) { showDialogError(nodes.clientDialog, '请填写调级或解锁原因'); return; }
    var expiryValue = document.getElementById('icl-client-expire').value;
    var expiresAt = expiryValue ? Math.floor(new Date(expiryValue).getTime() / 1000) : 0;
    setBusy(submitButton, true, '保存中…');
    request('/client', { method: 'PUT', body: JSON.stringify({ client_id: Number(document.getElementById('icl-client-id').value), level_id: levelId, manual_lock: lock ? 1 : 0, expires_at: expiresAt, reason: reason }) }).then(function () {
      closeDialog(nodes.clientDialog);
      return afterWriteRefresh('用户等级已更新', [loadClients(true), loadDashboard(true)]);
    }).catch(function (error) { showDialogError(nodes.clientDialog, error.message); }).then(function () { setBusy(submitButton, false); });
  });
  document.getElementById('icl-search-clients').addEventListener('click', function () {
    runReadAction(this, '搜索中…', function () { return loadClients(false); }, '用户搜索完成');
  });
  document.getElementById('icl-client-keywords').addEventListener('keydown', function (event) {
    if (event.key === 'Enter') { event.preventDefault(); document.getElementById('icl-search-clients').click(); }
  });

  function loadSettings(silent) {
    return request('/settings').then(function (data) {
      var settings = data.settings || {};
      document.getElementById('icl-auto-upgrade').checked = Number(settings.auto_upgrade) === 1;
      document.getElementById('icl-own-spend-level-enabled').checked = Number(settings.own_spend_level_enabled) === 1;
      document.getElementById('icl-referral-contribution-level-enabled').checked = Number(settings.referral_contribution_level_enabled) === 1;
      document.getElementById('icl-referral-enabled').checked = Number(settings.referral_enabled) === 1;
      document.getElementById('icl-referral-rate').value = money(settings.referral_reward_rate);
      document.getElementById('icl-contribution-rate').value = money(settings.contribution_exchange_rate);
      document.getElementById('icl-observation-days').value = Number(settings.referral_observation_days || 14);
      document.getElementById('icl-withdrawal-review-days').value = Number(settings.withdrawal_review_days || 7);
      document.getElementById('icl-min-withdraw').value = money(settings.min_withdraw_amount);
      document.getElementById('icl-invite-default-path').value = settings.invite_default_path || '/regist.htm';
      return true;
    }).catch(function (error) { if (!silent) showMessage(error.message, false); return false; });
  }
  document.getElementById('icl-settings-form').addEventListener('submit', function (event) {
    event.preventDefault();
    var submitButton = event.currentTarget.querySelector('button[type="submit"]');
    setBusy(submitButton, true, '保存中…');
    request('/settings', { method: 'PUT', body: JSON.stringify({
      auto_upgrade: document.getElementById('icl-auto-upgrade').checked ? 1 : 0,
      own_spend_level_enabled: document.getElementById('icl-own-spend-level-enabled').checked ? 1 : 0,
      referral_contribution_level_enabled: document.getElementById('icl-referral-contribution-level-enabled').checked ? 1 : 0,
      referral_enabled: document.getElementById('icl-referral-enabled').checked ? 1 : 0
      ,referral_reward_rate: document.getElementById('icl-referral-rate').value
      ,contribution_exchange_rate: document.getElementById('icl-contribution-rate').value
      ,referral_observation_days: Number(document.getElementById('icl-observation-days').value || 14)
      ,withdrawal_review_days: Number(document.getElementById('icl-withdrawal-review-days').value || 7)
      ,min_withdraw_amount: document.getElementById('icl-min-withdraw').value
      ,invite_default_path: document.getElementById('icl-invite-default-path').value.trim()
      ,default_allocation: 'manual'
    }) }).then(function () {
      return afterWriteRefresh('设置已保存', [loadSettings(true), loadDashboard(true)]);
    }).catch(function (error) { showMessage(error.message, false); }).then(function () { setBusy(submitButton, false); });
  });

  function loadProducts(page, silent) {
    state.productPage = Math.max(1, Number(page || state.productPage || 1));
    var keywords = document.getElementById('icl-product-keywords').value.trim();
    var query = '?page=' + state.productPage + '&limit=' + state.productLimit;
    if (keywords) query += '&keywords=' + encodeURIComponent(keywords);
    request('/products' + query).then(function (data) {
      state.productCount = Number(data.count || 0);
      state.productPage = Number(data.page || state.productPage);
      state.productLimit = Number(data.limit || state.productLimit);
      nodes.products.textContent = '';
      (data.list || []).forEach(function (product) {
        var row = document.createElement('tr');
        var productCell = make('td', 'icl-product-name');
        productCell.appendChild(make('strong', '', product.name || '未命名商品'));
        productCell.appendChild(make('small', '', '#' + product.id + (Number(product.product_id) > 0 ? ' · 子商品' : '')));
        row.appendChild(productCell);
        row.appendChild(make('td', '', product.group_path || '未分组'));
        row.appendChild(make('td', '', product.pay_type || '-'));
        row.appendChild(make('td', '', Number(product.hidden) === 1 ? '隐藏' : '显示'));
        var switchCell = make('td', 'icl-plan-cell');
        var label = make('label', 'icl-plan-switch');
        var text = make('span', '', Number(product.rebate_enabled) === 1 ? '已加入' : '不返利');
        var toggle = document.createElement('input');
        toggle.type = 'checkbox';
        toggle.checked = Number(product.rebate_enabled) === 1;
        toggle.setAttribute('aria-label', (product.name || '商品') + '加入返利计划');
        toggle.addEventListener('change', function () {
          var enabled = toggle.checked ? 1 : 0;
          toggle.disabled = true;
          request('/product/' + product.id + '/rebate', {
            method: 'PUT',
            body: JSON.stringify({ enabled: enabled })
          }).then(function () {
            text.textContent = enabled ? '已加入' : '不返利';
            text.classList.toggle('is-disabled', !enabled);
            showMessage(enabled ? '商品已加入返利计划' : '商品已移出返利计划', true);
          }).catch(function (error) {
            toggle.checked = !toggle.checked;
            showMessage(error.message, false);
          }).then(function () {
            toggle.disabled = false;
          });
        });
        text.classList.toggle('is-disabled', !toggle.checked);
        label.appendChild(text);
        label.appendChild(toggle);
        switchCell.appendChild(label);
        row.appendChild(switchCell);
        nodes.products.appendChild(row);
      });
      if (!data.list || !data.list.length) emptyRow(nodes.products, 5, '没有找到商品');
      var first = state.productCount > 0 ? ((state.productPage - 1) * state.productLimit) + 1 : 0;
      var last = Math.min(state.productCount, state.productPage * state.productLimit);
      document.getElementById('icl-product-summary').textContent = '商品共 ' + state.productCount + ' 个，当前显示 ' + first + '–' + last + '；未单独配置的商品默认加入。';
      document.getElementById('icl-product-page').textContent = '第 ' + state.productPage + ' / ' + Math.max(1, Math.ceil(state.productCount / state.productLimit)) + ' 页';
      document.getElementById('icl-product-prev').disabled = state.productPage <= 1;
      document.getElementById('icl-product-next').disabled = state.productPage * state.productLimit >= state.productCount;
      return true;
    }).catch(function (error) { if (!silent) showMessage(error.message, false); return false; });
  }
  document.getElementById('icl-search-products').addEventListener('click', function () {
    var button = this;
    runReadAction(button, '搜索中…', function () { return loadProducts(1, false); }, '商品搜索完成');
  });
  document.getElementById('icl-product-keywords').addEventListener('keydown', function (event) {
    if (event.key === 'Enter') { event.preventDefault(); document.getElementById('icl-search-products').click(); }
  });
  document.getElementById('icl-product-prev').addEventListener('click', function () {
    var button = this;
    runReadAction(button, '读取中…', function () { return loadProducts(state.productPage - 1, false); }, '商品列表已更新');
  });
  document.getElementById('icl-product-next').addEventListener('click', function () {
    var button = this;
    runReadAction(button, '读取中…', function () { return loadProducts(state.productPage + 1, false); }, '商品列表已更新');
  });

  function loadBinds(silent) {
    return request('/binds?limit=100').then(function (data) {
      nodes.binds.textContent = '';
      (data.list || []).forEach(function (bind) {
        var row = document.createElement('tr');
        row.appendChild(make('td', '', '#' + bind.referrer_client_id + ' ' + (bind.referrer_name || '')));
        row.appendChild(make('td', '', '#' + bind.invitee_client_id + ' ' + (bind.invitee_name || '')));
        row.appendChild(make('td', '', bind.source || '-'));
        row.appendChild(make('td', '', Number(bind.inherit_history) === 1 ? '是' : '否'));
        row.appendChild(make('td', '', Number(bind.contribution_start_time) === 0 ? '全部历史' : time(bind.contribution_start_time)));
        var risks = (bind.risk_flags || []).map(function (flag) {
          return flag === 'same_login_ip' ? '同登录 IP' : (flag === 'high_refund_ratio' ? '高退款比例' : flag);
        });
        row.appendChild(make('td', risks.length ? 'icl-risk-text' : '', risks.length ? risks.join('、') : '正常'));
        row.appendChild(make('td', '', Number(bind.status) === 1 ? '有效' : '历史'));
        nodes.binds.appendChild(row);
      });
      if (!data.list || !data.list.length) emptyRow(nodes.binds, 7, '暂无推广绑定');
      return true;
    }).catch(function (error) { if (!silent) showMessage(error.message, false); return false; });
  }
  document.getElementById('icl-refresh-binds').addEventListener('click', function () {
    var button = this;
    runReadAction(button, '刷新中…', function () { return loadBinds(false); }, '推广绑定已更新');
  });
  document.getElementById('icl-bind-form').addEventListener('submit', function (event) {
    event.preventDefault();
    var submitButton = event.currentTarget.querySelector('button[type="submit"]');
    var inheritHistory = document.getElementById('icl-bind-history').checked;
    if (inheritHistory && !window.confirm('继承历史会把客户绑定前符合条件的订单纳入返利计算，并留下操作记录。确定继续吗？')) return;
    setBusy(submitButton, true, '保存中…');
    request('/bind', { method: 'POST', body: JSON.stringify({
      referrer_client_id: Number(document.getElementById('icl-bind-referrer').value || 0),
      invitee_client_id: Number(document.getElementById('icl-bind-invitee').value || 0),
      inherit_history: inheritHistory ? 1 : 0
    }) }).then(function () {
      return afterWriteRefresh('推广关系已保存', [loadBinds(true)]);
    }).catch(function (error) { showMessage(error.message, false); }).then(function () { setBusy(submitButton, false); });
  });
  document.getElementById('icl-half-agent-preview').addEventListener('click', function () {
    var actionButton = this;
    setBusy(actionButton, true, '检查中…');
    request('/half_agent/import', { method: 'POST', body: JSON.stringify({ execute: 0 }) }).then(function (data) {
      var conflicts = (data.conflicts || []).length;
      var text = '可导入推广人 ' + Number(data.profiles || 0) + ' 个、绑定 ' + Number(data.binds || 0) + ' 条、冲突 ' + conflicts + ' 条。\n\n旧会员等级、消费汇总、钱包和提现不会自动导入。';
      if (!Number(data.available)) { showMessage('未检测到可导入的 HalfAgent 数据', true); return null; }
      if (!window.confirm(text + '\n\n是否执行安全导入？')) return null;
      return request('/half_agent/import', { method: 'POST', body: JSON.stringify({ execute: 1 }) });
    }).then(function (result) {
      if (result) {
        var failed = (result.failures || []).length;
        var imported = Number(result.imported_profiles || 0) + Number(result.imported_binds || 0);
        var resultText = failed
          ? 'HalfAgent 导入完成：成功 ' + imported + ' 项，未导入 ' + failed + ' 项，请查看冲突后重试'
          : 'HalfAgent 推广关系导入完成';
        return afterWriteRefresh(resultText, [loadBinds(true)]);
      }
      return null;
    }).catch(function (error) { showMessage(error.message, false); }).then(function () { setBusy(actionButton, false); });
  });

  function loadLogs(silent) {
    return request('/logs?limit=100').then(function (data) {
      nodes.logs.textContent = '';
      (data.list || []).forEach(function (log) {
        var row = document.createElement('tr');
        [time(log.create_time), '#' + log.client_id + ' ' + (log.username || ''), log.old_level_name || '-', log.new_level_name || '-', money(log.amount_before) + ' → ' + money(log.amount_after), log.source || '-', log.order_id ? '#' + log.order_id : '-'].forEach(function (value) { row.appendChild(make('td', '', value)); });
        nodes.logs.appendChild(row);
      });
      if (!data.list || !data.list.length) emptyRow(nodes.logs, 7, '暂无等级变更记录');
      return true;
    }).catch(function (error) { if (!silent) showMessage(error.message, false); return false; });
  }
  document.getElementById('icl-refresh-logs').addEventListener('click', function () {
    var button = this;
    runReadAction(button, '刷新中…', function () { return loadLogs(false); }, '变更记录已更新');
  });
  document.getElementById('icl-rebuild-all').addEventListener('click', function () {
    var actionButton = this;
    if (!window.confirm('将读取全部已支付/已退款订单并重算累计净消费。管理员固定的等级不会被覆盖，确定继续吗？')) return;
    setBusy(actionButton, true, '重算中…');
    request('/rebuild', { method: 'POST', body: JSON.stringify({ client_id: 0 }) }).then(function (data) {
      return afterWriteRefresh('重算完成，共处理 ' + (data.updated_clients || 0) + ' 个用户', [loadDashboard(true), loadClients(true)]);
    }).catch(function (error) { showMessage(error.message, false); }).then(function () { setBusy(actionButton, false); });
  });

  loadDashboard();
  loadLevels();
})();
