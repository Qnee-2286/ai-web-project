(() => {
  const API = '../api';
  const authPages = ['login.html', 'register.html', 'entry.html', 'set-password.html'];
  const page = location.pathname.split('/').pop() || 'dashboard.html';

  function $(sel, root = document) { return root.querySelector(sel); }
  function inputs(form) { return Array.from(form.querySelectorAll('input')); }
  function message(form, text, ok = false) {
    let box = form.querySelector('.form-message');
    if (!box) {
      box = document.createElement('div');
      box.className = 'form-message';
      form.prepend(box);
    }
    box.textContent = text;
    box.classList.toggle('ok', ok);
  }
  // 读取地址栏 ?redirect= 参数（root-relative，如 hr/payment.html）
  function redirectParam() {
    return new URLSearchParams(location.search).get('redirect') || '';
  }
  // 把 root-relative（如 hr/payment.html）归一为当前 hr/ 目录下的相对路径，并防止开放重定向
  function resolveRedirect(r) {
    if (!r) return '';
    if (/^[a-z][a-z0-9+.-]*:\/\//i.test(r) || r.indexOf('//') === 0) return ''; // 仅允许相对路径
    if (r.indexOf('hr/') === 0) return './' + r.slice(3);
    if (r.indexOf('/hr/') === 0) return './' + r.slice(4);
    return r;
  }
  // 给目标 URL 透传当前 redirect 参数（用于 注册→绑邮箱→实名 的引导链路）
  function withRedirect(url) {
    const r = redirectParam();
    if (!r) return url;
    return url + (url.indexOf('?') >= 0 ? '&' : '?') + 'redirect=' + encodeURIComponent(r);
  }
  async function post(path, payload) {
    const res = await fetch(`${API}/${path}`, {
      method: 'POST',
      headers: {'Content-Type': 'application/json'},
      credentials: 'include',
      body: JSON.stringify(payload)
    });
    const data = await res.json().catch(() => ({ok:false, message:'接口返回格式错误'}));
    if (data && data.data && data.data.need_login) {
      location.href = './login.html';
      return new Promise(() => {});
    }
    if (!res.ok || !data.ok) throw new Error((data.message || '请求失败') + (data.data && data.data.error ? `：${data.data.error}` : ''));
    return data;
  }
  async function get(path) {
    const res = await fetch(`${API}/${path}`, { credentials: 'include' });
    const data = await res.json().catch(() => ({ok:false, message:'接口返回格式错误'}));
    if (data && data.data && data.data.need_login) {
      location.href = './login.html';
      return new Promise(() => {});
    }
    if (!res.ok || !data.ok) throw new Error((data.message || '请求失败') + (data.data && data.data.error ? `：${data.data.error}` : ''));
    return data;
  }
  function addSendCodeButton(input, onClick) {
    if (!input || input.dataset.codeButtonAdded) return;
    input.dataset.codeButtonAdded = '1';
    const btn = document.createElement('button');
    btn.type = 'button';
    btn.className = 'inline-code-button';
    btn.textContent = '发送验证码';
    input.insertAdjacentElement('afterend', btn);
    const startCooldown = () => {
      let left = 60;
      window.clearInterval(btn._codeTimer);
      btn.disabled = true;
      btn.textContent = `重新发送(${left}s)`;
      btn._codeTimer = window.setInterval(() => {
        left -= 1;
        if (left <= 0) {
          window.clearInterval(btn._codeTimer);
          btn.disabled = false;
          btn.textContent = '重新发送';
          return;
        }
        btn.textContent = `重新发送(${left}s)`;
      }, 1000);
    };
    btn.addEventListener('click', async () => {
      btn.disabled = true;
      btn.textContent = '发送中...';
      try {
        await onClick();
        startCooldown();
      } catch (err) {
        alert(err.message);
        window.clearInterval(btn._codeTimer);
        btn.disabled = false;
        btn.textContent = '重新发送';
      }
    });
  }
  function setupPasswordToggles(root = document) {
    root.querySelectorAll('.password-toggle[data-target]').forEach((button) => {
      if (button.dataset.toggleReady) return;
      button.dataset.toggleReady = '1';
      const input = document.getElementById(button.dataset.target);
      if (!input) return;
      button.addEventListener('click', () => {
        const visible = input.type === 'text';
        input.type = visible ? 'password' : 'text';
        button.textContent = visible ? '显示' : '隐藏';
      });
    });
  }
  /* ---------- 会员标识（仅首页和会员中心显示） ---------- */
  var badgePages = ['dashboard.html', 'membership.html'];
  function setupMembershipBadge() {
    if (!badgePages.includes(page)) return;
    // 注入 badge CSS（仅一次）
    if (!document.getElementById('mbBadgeCSS')) {
      var css = document.createElement('style');
      css.id = 'mbBadgeCSS';
      css.textContent = [
        '@keyframes mbGlow{0%,100%{box-shadow:0 0 4px rgba(255,183,0,.25)}50%{box-shadow:0 0 10px rgba(255,183,0,.5)}}',
        '.mb-pill{display:inline-flex;align-items:center;gap:7px;padding:5px 14px 5px 6px;border-radius:999px;flex-shrink:0;transition:all .3s}',
        '.mb-pill.lit{background:linear-gradient(135deg,rgba(255,215,0,.10),rgba(255,160,0,.08));border:1px solid rgba(255,183,0,.22)}',
        '.mb-pill.dim{background:#F8FAFC;border:1px solid #E2E8F0}',
        '.mb-pill-icon{width:24px;height:24px;border-radius:50%;display:flex;align-items:center;justify-content:center;flex-shrink:0}',
        '.mb-pill.lit .mb-pill-icon{background:linear-gradient(135deg,#FFD700,#FFA000);animation:mbGlow 2.5s ease-in-out infinite}',
        '.mb-pill.dim .mb-pill-icon{background:#E2E8F0}',
        '.mb-pill-icon svg{width:13px;height:13px}',
        '.mb-pill.lit .mb-pill-icon svg{color:#fff}',
        '.mb-pill.dim .mb-pill-icon svg{color:#94A3B8}',
        '.mb-pill-info{display:flex;flex-direction:column;line-height:1.25}',
        '.mb-pill-label{font-size:12px;font-weight:700;white-space:nowrap}',
        '.mb-pill.lit .mb-pill-label{color:#B8860B}',
        '.mb-pill.dim .mb-pill-label{color:#94A3B8}',
        '.mb-pill-sub{font-size:10px;white-space:nowrap}',
        '.mb-pill.lit .mb-pill-sub{color:#C89B3C}',
        '.mb-pill.dim .mb-pill-sub{color:#B0BEC5}'
      ].join('\n');
      document.head.appendChild(css);
    }
    // 皇冠 SVG 路径
    var crownSVG = '<svg viewBox="0 0 24 24" fill="currentColor"><path d="M5 16L3 5l5.5 5L12 4l3.5 6L21 5l-2 11H5zm14 3c0 .6-.4 1-1 1H6c-.6 0-1-.4-1-1v-1h14v1z"/></svg>';
    // 找到 topbar-account 并注入 badge 元素（排在用户信息前面）
    var topbarAccount = document.querySelector('.topbar-account');
    if (!topbarAccount) return;
    var pill = document.createElement('div');
    pill.id = 'membershipBadge';
    pill.className = 'mb-pill dim';
    pill.innerHTML = '<div class="mb-pill-icon">' + crownSVG + '</div><div class="mb-pill-info"><span class="mb-pill-label">加载中…</span><span class="mb-pill-sub">—</span></div>';
    topbarAccount.insertBefore(pill, topbarAccount.firstChild);
    // 请求会员状态 API
    get('hr/membership_status.php').then(function(res) {
      var d = res.data;
      var label = pill.querySelector('.mb-pill-label');
      var sub = pill.querySelector('.mb-pill-sub');
      if (d.active) {
        pill.className = 'mb-pill lit';
        label.textContent = d.plan_name;
        sub.textContent = '剩余 ' + d.remain_interviews + ' 次 · ' + d.remain_days + '天';
      } else {
        label.textContent = '体验版';
        sub.textContent = d.remain_interviews != null ? '剩余 ' + d.remain_interviews + ' 次' : '';
      }
      // 首页权益面板也同步更新
      if (page === 'dashboard.html') {
        var benefitTitle = document.querySelector('.benefit-panel h2');
        var benefitDesc = document.querySelector('.benefit-panel p');
        var trialEl = document.getElementById('trialUsage');
        var modeEl = document.getElementById('dashboardMode');
        if (d.active) {
          if (benefitTitle) benefitTitle.textContent = d.plan_name + '权益';
          if (benefitDesc) benefitDesc.textContent = '可发起 ' + d.total_interviews + ' 次候选人初面，已使用 ' + d.used_interviews + ' 次。会员到期日：' + d.expires_at + '。';
          if (trialEl) trialEl.textContent = '剩余 ' + d.remain_interviews + ' 次';
          if (modeEl) modeEl.textContent = '当前账号：' + d.plan_name;
        } else {
          if (trialEl) trialEl.textContent = d.total_interviews ? '剩余 ' + d.remain_interviews + ' 次' : '剩余额度待统计';
        }
      }
    }).catch(function() {
      var label = pill.querySelector('.mb-pill-label');
      if (label) label.textContent = '体验版';
      var sub = pill.querySelector('.mb-pill-sub');
      if (sub) sub.textContent = '';
    });
  }

  async function guard() {
    if (authPages.includes(page)) return;
    try { await get('auth/me.php'); }
    catch (_) { location.href = './login.html'; }
  }

  async function updateDashboardStatus() {
    if (page !== 'dashboard.html') return;
    try {
      const {data} = await get('auth/me.php');
      const icons = Array.from(document.querySelectorAll('.account-icons span'));
      if (icons[1]) icons[1].classList.toggle('verified', !!data.email_verified);
      if (icons[2]) icons[2].classList.toggle('verified', data.realname_status === 'verified');
      if (icons[0]) icons[0].classList.toggle('verified', data.company_verification_status === 'verified');
      const nameEl = document.getElementById('dashboardUserName');
      const metaEl = document.getElementById('dashboardUserMeta');
      const avatarEl = document.getElementById('dashboardAvatar');
      if (nameEl) nameEl.textContent = data.name || '当前HR';
      if (metaEl) metaEl.textContent = `${data.phone || ''}${data.email ? ' · ' + data.email : ''}`;
      if (avatarEl) avatarEl.textContent = (data.name || 'HR').slice(0, 2).toUpperCase();
    } catch (_) {}
  }

  function statusText(status) {
    const map = {
      not_received: '未接收',
      pending_interview: '待面试',
      interviewing: '面试中',
      completed: '已完成面试',
      review_pending: '待复核',
      rejected: '暂不推进',
      continue: '继续推进',
      hold: '待定',
      reject: '暂不推进',
      ready: '待复核',
      reviewed: '已复核',
      report_ready: '报告已生成'
    };
    return map[status] || '待处理';
  }

  function realnameText(status) {
    if (status === 'verified') return '已实名';
    if (status === 'failed') return '实名失败';
    return '未实名';
  }

  function fmtTime(value) {
    if (!value) return '-';
    return String(value).replace('T', ' ').slice(0, 16);
  }
  function esc(value) {
    return String(value ?? '').replace(/[&<>"']/g, (ch) => ({
      '&': '&amp;',
      '<': '&lt;',
      '>': '&gt;',
      '"': '&quot;',
      "'": '&#39;'
    }[ch]));
  }

  function setupDashboardData() {
    if (page !== 'dashboard.html') return;
    get('hr/dashboard_summary.php').then(({data}) => {
      const review = document.getElementById('dashReviewPending');
      const pending = document.getElementById('dashPendingCandidates');
      const active = document.getElementById('dashActiveJobs');
      const recent = document.getElementById('dashboardRecentJobs');
      if (review) review.textContent = `${data.review_pending || 0}份`;
      if (pending) pending.textContent = `${data.pending_candidates || 0}人`;
      if (active) active.textContent = `${data.job_count || 0}场`;
      if (!recent) return;
      recent.innerHTML = '';
      const jobs = data.recent_jobs || [];
      if (!jobs.length) {
        recent.innerHTML = '<tr><td colspan="4">暂无初面任务，请先创建一场AI初面。</td></tr>';
        return;
      }
      jobs.forEach((job) => {
        const tr = document.createElement('tr');
        const needsReview = Number(job.review_pending_count || 0);
        tr.innerHTML = `
          <td><strong>${job.job_title || '-'}</strong><br><small>${formatAuthJobLocation(job)}</small></td>
          <td>${job.candidate_count || 0}人</td>
          <td><span class="tag ${needsReview ? 'orange' : 'blue'}">${needsReview ? '待复核' : '进行中'}</span></td>
          <td><a class="primary-link" href="${needsReview ? './report-review-list.html' : './candidate-list.html'}">${needsReview ? '处理报告' : '查看候选人'}</a></td>`;
        recent.appendChild(tr);
      });
    }).catch(() => {});
  }

  function setupRegister() {
    if (page !== 'register.html') return;
    const form = $('form'); if (!form) return;
    const nameInput = document.getElementById('registerName') || inputs(form)[0];
    const phoneInput = document.getElementById('registerPhone') || inputs(form)[1];
    const codeInput = document.getElementById('registerSmsCode') || inputs(form)[2];
    const passwordInput = document.getElementById('registerPassword') || inputs(form)[3];
    const confirmInput = document.getElementById('registerPasswordConfirm') || inputs(form)[4];
    const agreementInput = document.getElementById('registerAgreement') || inputs(form)[5];
    setupPasswordToggles(form);
    addSendCodeButton(codeInput, async () => {
      const phone = phoneInput.value.trim();
      await post('auth/send_sms_code.php', {phone, purpose:'hr_register'});
      message(form, '验证码已发送，请查看手机短信。', true);
    });
    $('.btn.primary', form)?.addEventListener('click', async (e) => {
      e.preventDefault();
      try {
        const res = await post('auth/hr_register.php', {
          name: nameInput.value.trim(), phone: phoneInput.value.trim(), sms_code: codeInput.value.trim(),
          password: passwordInput.value, password_confirm: confirmInput.value, agreement: agreementInput.checked
        });
        message(form, res.message, true);
        location.href = withRedirect(res.data.next || './bind-email.html');
      } catch (err) { message(form, err.message); }
    });
  }
  function setupEmail() {
    if (page !== 'bind-email.html') return;
    const form = $('form'); if (!form) return;
    const emailInput = document.getElementById('bindEmail') || inputs(form)[0];
    const codeInput = document.getElementById('bindEmailCode') || inputs(form)[1];
    const agreementInput = document.getElementById('bindEmailAgreement') || inputs(form)[2];
    addSendCodeButton(codeInput, async () => {
      const email = emailInput.value.trim();
      await post('auth/send_email_code.php', {email});
      message(form, '邮箱验证码已发送，请查看收件箱或垃圾邮件。', true);
    });
    const submitEmail = async (e) => {
      e.preventDefault();
      try {
        const res = await post('auth/bind_email.php', {email: emailInput.value.trim(), email_code: codeInput.value.trim(), agreement: agreementInput.checked});
        message(form, res.message, true);
        location.href = withRedirect(res.data.next || './realname.html');
      } catch (err) { message(form, err.message); }
    };
    form.addEventListener('submit', submitEmail);
    $('.btn.primary', form)?.addEventListener('click', (e) => {
      if (e.currentTarget.tagName.toLowerCase() !== 'button') submitEmail(e);
    });
  }
  function setupRealname() {
    if (page !== 'realname.html') return;
    const form = $('form'); if (!form) return;
    const modal = document.getElementById('hrRealnameModal');
    const modalForm = document.getElementById('hrRealnameModalForm');
    const statusText = document.getElementById('hrRealnameStatusText');
    const qrPanel = document.getElementById('hrRealnameQrPanel');
    const qr = document.getElementById('hrRealnameQr');
    const openLink = document.getElementById('hrRealnameOpenLink');
    const refreshBtn = document.getElementById('hrRealnameRefresh');
    const regenerateBtn = document.getElementById('hrRealnameRegenerate');
    const dashboardBtn = document.getElementById('hrRealnameDashboard');
    const actionBtn = document.getElementById('hrRealnameAction') || $('.btn.primary', form);
    const closeBtn = document.getElementById('hrRealnameModalClose');
    const cancelBtn = document.getElementById('hrRealnameModalCancel');
    const isMobile = /Android|iPhone|iPad|iPod|Mobile/i.test(navigator.userAgent);
    const showModal = () => {
      if (!modal) return;
      modal.hidden = false;
      modal.style.display = 'flex';
      modal.setAttribute('aria-hidden', 'false');
    };
    const hideModal = () => {
      if (!modal) return;
      modal.hidden = true;
      modal.style.display = 'none';
      modal.setAttribute('aria-hidden', 'true');
    };
    const renderQr = (url) => {
      if (!qr) return;
      qr.innerHTML = '';
      const qrFactory = window.qrcode || (typeof qrcode !== 'undefined' ? qrcode : null);
      if (qrFactory) {
        try {
          const code = qrFactory(0, 'L');
          code.addData(url);
          code.make();
          const img = document.createElement('img');
          img.alt = '微信实名核身二维码';
          img.src = code.createDataURL(6, 6);
          qr.appendChild(img);
        } catch (err) {
          qr.innerHTML = '<strong>二维码生成失败</strong><span>请点右侧链接打开</span>';
          return;
        }
        const label = document.createElement('span');
        label.textContent = '微信扫码';
        qr.appendChild(label);
      } else {
        qr.innerHTML = '<strong>二维码组件未加载</strong><span>请点右侧链接打开</span>';
      }
    };
    const showPending = (data) => {
      const url = data.redirect_url;
      if (statusText) statusText.textContent = '认证中';
      if (qrPanel) qrPanel.hidden = false;
      if (openLink) {
        openLink.href = url;
        openLink.hidden = false;
      }
      renderQr(url);
      if (refreshBtn) refreshBtn.hidden = false;
      if (regenerateBtn) regenerateBtn.hidden = false;
      if (actionBtn) actionBtn.hidden = true;
      if (dashboardBtn) dashboardBtn.hidden = true;
      if (data.expires_at) message(form, `实名核身已发起，二维码有效期至 ${data.expires_at}`, true);
    };
    const checkStatus = async () => {
      const res = await get('auth/hr_realname_status.php');
      const status = res.data.realname_status;
      if (status === 'verified') {
        if (statusText) statusText.textContent = '已实名';
        message(form, '实名认证已通过，正在进入工作台', true);
        if (qrPanel) qrPanel.hidden = true;
        if (refreshBtn) refreshBtn.hidden = true;
        if (regenerateBtn) regenerateBtn.hidden = true;
        if (dashboardBtn) dashboardBtn.hidden = false;
        setTimeout(() => { location.href = resolveRedirect(redirectParam()) || './dashboard.html'; }, 800);
        return;
      }
      if (status === 'failed' || status === 'expired') {
        if (statusText) statusText.textContent = status === 'expired' ? '已过期' : '认证未通过';
        message(form, res.data.fail_reason || (status === 'expired' ? '实名核身链接已过期，请重新生成二维码。' : '实名认证未通过，请重新生成二维码。'));
        if (actionBtn) {
          actionBtn.hidden = false;
          actionBtn.textContent = '重新生成二维码';
        }
        if (refreshBtn) refreshBtn.hidden = true;
        return;
      }
      if (statusText) statusText.textContent = '认证中';
      message(form, '暂未查询到通过结果，请完成微信核身后再刷新。');
    };
    actionBtn?.addEventListener('click', async (e) => {
      e.preventDefault();
      showModal();
    });
    regenerateBtn?.addEventListener('click', async (e) => {
      e.preventDefault();
      showModal();
    });
    closeBtn?.addEventListener('click', hideModal);
    cancelBtn?.addEventListener('click', hideModal);
    modal?.addEventListener('click', (e) => {
      if (e.target === modal) hideModal();
    });
    modalForm?.addEventListener('submit', async (e) => {
      e.preventDefault();
      try {
        const fd = new FormData(modalForm);
        const res = await post('auth/hr_realname_start.php', {
          name: String(fd.get('name') || '').trim(),
          id_card: String(fd.get('id_card') || '').trim(),
          agreement: !!fd.get('agreement')
        });
        if (res.data && res.data.redirect_url) {
          hideModal();
          showPending(res.data);
          if (isMobile) setTimeout(() => { location.href = res.data.redirect_url; }, 600);
          return;
        }
        if (res.data && res.data.realname_status === 'verified') {
          location.href = resolveRedirect(redirectParam()) || './dashboard.html';
          return;
        }
        message(form, res.message || '实名核身已发起', true);
      } catch (err) { message(form, err.message); }
    });
    refreshBtn?.addEventListener('click', async (e) => {
      e.preventDefault();
      try { await checkStatus(); }
      catch (err) { message(form, err.message); }
    });
    checkStatus().catch(() => {});
  }
  function setupLogin() {
    if (page !== 'login.html') return;
    const form = $('form'); if (!form) return;
    // 若带 ?redirect=（如从支付页跳来），让"注册"链接也透传 redirect，方便新用户注册后回到原目标
    const rd = redirectParam();
    if (rd) {
      const regLink = document.querySelector('a[href="./register.html"]');
      if (regLink) regLink.href = withRedirect('./register.html');
    }
    const loginInput = document.getElementById('loginAccount') || form.querySelector('input[name="login"]') || inputs(form)[0];
    const passwordInput = document.getElementById('loginPassword');
    const codeInput = document.getElementById('loginCode');
    const methodSelect = document.getElementById('loginMethod');
    addSendCodeButton(codeInput, async () => {
      const login = loginInput.value.trim();
      await post('auth/send_login_code.php', {login});
      message(form, '登录验证码已发送，请查看短信或邮箱。', true);
    });
    $('.btn.primary', form)?.addEventListener('click', async (e) => {
      e.preventDefault();
      try {
        const mode = (methodSelect?.value || '').includes('验证码') ? 'code' : 'password';
        const credential = mode === 'code' ? codeInput.value : passwordInput.value;
        const res = await post('auth/hr_login.php', {login: loginInput.value.trim(), credential, mode});
        message(form, res.message, true);
        location.href = resolveRedirect(redirectParam()) || res.data.next || './dashboard.html';
      } catch (err) { message(form, err.message); }
    });
  }

  function fillForm(form, data) {
    if (!form) return;
    Object.keys(data || {}).forEach(k => {
      const el = form.querySelector(`[name="${k}"]`);
      if (el) el.value = data[k] || '';
    });
  }
  function formPayload(form) {
    return normalizeAuthJobTaxonomyPayload(Object.fromEntries(new FormData(form).entries()));
  }
  function disableAutocomplete(form) {
    if (!form) return;
    form.setAttribute('autocomplete', 'off');
    form.querySelectorAll('input, textarea').forEach((field) => {
      field.setAttribute('autocomplete', 'off');
      field.setAttribute('autocapitalize', 'off');
      field.setAttribute('spellcheck', 'false');
      field.addEventListener('focus', () => {
        field.setAttribute('readonly', 'readonly');
        setTimeout(() => field.removeAttribute('readonly'), 0);
      }, { once: true });
    });
  }
  function setupBenefitPicker(root = document) {
    root.querySelectorAll('.benefit-picker').forEach((picker) => {
      if (picker.dataset.benefitReady) return;
      picker.dataset.benefitReady = '1';
      const input = picker.querySelector('input[name="benefits"]');
      const tags = Array.from(picker.querySelectorAll('.benefit-tag'));
      const customInput = picker.querySelector('.benefit-custom-input');
      const addButton = picker.querySelector('.benefit-custom-add');
      const summary = picker.querySelector('.benefit-summary');
      if (!input || !tags.length) return;
      const parse = () => String(input.value || '').split(/[、，,\s]+/).map((item) => item.trim()).filter(Boolean);
      const setSelected = (items) => {
        input.value = Array.from(new Set(items.filter(Boolean))).join(' ');
        input.dispatchEvent(new Event('input', {bubbles: true}));
      };
      const sync = () => {
        const selectedItems = parse();
        const selected = new Set(selectedItems);
        tags.forEach((tag) => tag.classList.toggle('active', selected.has(tag.textContent.trim())));
        if (summary) {
          summary.textContent = selectedItems.length ? `已选：${selectedItems.join('、')}` : '已选福利会在候选人预览中展示';
          summary.classList.toggle('has-value', selectedItems.length > 0);
        }
      };
      tags.forEach((tag) => {
        tag.addEventListener('click', () => {
          const text = tag.textContent.trim();
          const selected = parse();
          const index = selected.indexOf(text);
          if (index >= 0) selected.splice(index, 1);
          else selected.push(text);
          setSelected(selected);
        });
      });
      const addCustom = () => {
        const text = (customInput?.value || '').trim();
        if (!text) return;
        setSelected([...parse(), text]);
        customInput.value = '';
        customInput.focus();
      };
      addButton?.addEventListener('click', addCustom);
      customInput?.addEventListener('keydown', (event) => {
        if (event.key === 'Enter') {
          event.preventDefault();
          addCustom();
        }
      });
      input.addEventListener('input', sync);
      sync();
    });
  }
  function setText(sel, text, root = document) {
    const el = root.querySelector(sel);
    if (el) el.textContent = text || '';
  }
  const authJobTypeLabels = {
    sales_business: '销售/商务',
    marketing_operation: '市场/运营',
    customer_service: '客服/服务',
    retail_store: '门店/零售',
    logistics_delivery: '物流/仓储/配送',
    production_manufacturing: '生产/制造',
    food_hospitality: '餐饮/酒店',
    life_property_service: '家政/生活服务',
    administration: '职能/行政',
    human_resources: '人力资源',
    finance_legal: '财务/法务',
    tech_rd: '技术/研发',
    product_design: '产品/设计',
    education_training: '教育/培训',
    medical_health: '医疗/健康',
    construction_property: '建筑/工程/物业',
    flexible_parttime: '灵活用工/兼职',
    other: '其他'
  };
  const authJobLevelLabels = {
    intern_parttime: '实习/兼职',
    entry_staff: '基层员工',
    senior_staff: '资深员工',
    lead_supervisor: '主管/组长',
    manager: '经理',
    director_plus: '总监及以上',
    staff: '基层员工',
    management: '经理'
  };
  function normalizeAuthJobTaxonomyPayload(payload) {
    const next = {...payload};
    if (next.job_type === 'custom') next.job_type = String(next.job_type_custom || '').trim();
    if (next.job_direction === 'custom') next.job_direction = String(next.job_direction_custom || '').trim();
    return next;
  }
  function setupCreateJob() {
    if (page !== 'create-job.html') return;
    const form = document.getElementById('jobForm');
    const saveBtn = document.getElementById('saveJobBtn');
    if (!form || !saveBtn) return;
    disableAutocomplete(form);
    setupBenefitPicker(form);
    saveBtn.addEventListener('click', () => {
      const payload = formPayload(form);
      sessionStorage.setItem('job_preview_payload', JSON.stringify(payload));
      location.href = './create-interview-flow.html?v=20260615cachefix';
    });
  }
  function setupJobPreview() {
    if (page !== 'preview-job.html') return;
    const form = document.getElementById('jobPreviewPanel');
    const saved = JSON.parse(sessionStorage.getItem('job_preview_payload') || '{}');
    const payload = Object.keys(saved).length ? saved : {
      company_name: '',
      job_title: '',
      job_type: '',
      job_direction: '',
      job_level: 'entry_staff',
      question_bank: '',
      salary_min_k: '',
      salary_max_k: '',
      benefits: '',
      company_intro: '',
      responsibilities: '',
      requirements: ''
    };
    setText('[data-preview="company_name"]', payload.company_name || '未填写');
    setText('[data-preview="job_title"]', payload.job_title || '未填写');
    setText('[data-preview="job_type"]', [authJobTypeLabels[payload.job_type] || payload.job_type, payload.job_direction].filter(Boolean).join(' · ') || '未选择');
    setText('[data-preview="job_level"]', authJobLevelLabels[payload.job_level] || '基层员工');
    setText('[data-preview="question_bank"]', payload.question_bank || '未选择');
    setText('[data-preview="salary"]', formatAuthJobSalary(payload));
    setText('[data-preview="benefits"]', payload.benefits || '未填写');
    setText('[data-preview="company_intro"]', payload.company_intro || '未填写');
    setText('[data-preview="responsibilities"]', payload.responsibilities || '未填写');
    setText('[data-preview="requirements"]', payload.requirements || '未填写');
    document.getElementById('confirmSaveJob')?.addEventListener('click', async () => {
      try {
        await post('hr/create_job.php', payload);
      } catch (_) {
        // Keep the preview flow moving even if an API error needs later follow-up.
      }
      sessionStorage.removeItem('job_preview_payload');
      location.href = './job-management.html';
    });
  }
  function formatAuthJobLocation(job) {
    const type = authJobTypeLabels[job.job_type] || '';
    const direction = String(job.job_direction || '').trim();
    const level = authJobLevelLabels[job.job_level] || '';
    return [type || job.job_type, direction, level].filter(Boolean).join(' · ') || String(job.work_location || '').trim() || '岗位类型待补充';
  }

  function formatAuthJobSalary(job) {
    if ((job.salary_unit || 'K/月') === '面议') return '面议';
    if ((job.salary_unit || 'K/月') === '元/天') return (job.salary_min_k || '-') + '-' + (job.salary_max_k || '-') + '元/天';
    return (job.salary_min_k || '-') + '-' + (job.salary_max_k || '-') + 'K/月';
  }

  function renderJobCard(job) {
    const section = document.createElement('section');
    section.className = 'job-card';
    const isActive = job.status === 'active';
    const statusText = isActive ? '进行中' : '已关闭';
    const statusClass = isActive ? 'green' : 'gray';
    section.innerHTML = `
      <div class="job-card-header">
        <div>
          <h2>${job.job_title || ''}</h2>
          <p>${job.company_name || '公司信息待补充'}</p>
          <div class="job-meta">
            <span class="tag ${statusClass}"><span class="dot"></span>${statusText}</span>
            <span class="tag amber"><span class="dot"></span>${formatAuthJobLocation(job)}</span>
            <span class="tag blue">${formatAuthJobSalary(job)}</span>
            <span class="tag gray">${job.benefits || '福利待补充'}</span>
          </div>
        </div>
      </div>
      <div class="job-card-body">
        <div class="mini-stats compact">
          <article class="mini-stat"><span>候选人总数</span><strong>${job.candidate_count || 0}</strong><small>已发送邀请</small></article>
          <article class="mini-stat"><span>已完成初面</span><strong>${job.completed_count || 0}</strong><small>面试已结束</small></article>
          <article class="mini-stat"><span>待复核报告</span><strong>${job.review_pending_count || 0}</strong><small>需要HR审阅</small></article>
          <article class="mini-stat"><span>待面试</span><strong>${job.pending_count || 0}</strong><small>尚未开始</small></article>
        </div>
      </div>
      <div class="job-card-footer">
        <a class="btn" href="./candidate-list.html?job_id=${job.id}">📋 查看候选人</a>
        <a class="btn" href="./report-review-list.html?job_id=${job.id}">📊 报告复核</a>
        <a class="btn outline-primary" href="./create-interview-flow.html?v=20260615cachefix&job_id=${job.id}&step=1">✦ 编辑初面</a>
      </div>`;
    return section;
  }
  function setupJobManagement() {
    if (page !== 'job-management.html') return;
    const list = document.querySelector('.job-list');
    if (!list) return;
    get('hr/list_jobs.php').then(({data}) => {
      list.innerHTML = '';
      if (!data.jobs || data.jobs.length === 0) {
        const empty = document.createElement('section');
        empty.className = 'panel empty-state';
        empty.innerHTML = '<div class="empty-icon"><svg viewBox="0 0 48 48"><path d="M28 4H12a4 4 0 00-4 4v32a4 4 0 004 4h24a4 4 0 004-4V16L28 4z"/><polyline points="28,4 28,16 40,16"/><line x1="16" y1="26" x2="32" y2="26"/><line x1="16" y1="34" x2="26" y2="34"/></svg></div><h2>还没有初面任务</h2><p>新账号不会预置岗位。请先创建一场AI初面，系统会引导你完成岗位信息、问题配置和链接发布。</p><a class="btn primary" href="./create-interview-flow.html?v=20260615cachefix">✦ 创建初面</a>';
        list.appendChild(empty);
        return;
      }
      data.jobs.forEach(job => list.appendChild(renderJobCard(job)));
    }).catch((err) => {
      list.innerHTML = `<section class="panel"><p class="hint">${err.message}</p></section>`;
    });
  }

  function setupCandidateList() {
    if (page !== 'candidate-list.html') return;
    const rows = document.getElementById('candidateRows');
    if (!rows) return;
    get('hr/list_candidates.php').then(({data}) => {
      rows.innerHTML = '';
      const candidates = data.candidates || [];
      if (!candidates.length) {
        rows.innerHTML = '<tr><td colspan="9">暂无候选人。请先创建初面并生成候选人链接。</td></tr>';
        return;
      }
      candidates.forEach((item) => {
        const tr = document.createElement('tr');
        const resumeStatus = item.resume_name ? '<span class="tag green">已上传</span>' : '<span class="tag gray">未上传</span>';
        const candidateName = item.real_name || `候选人#${item.id}`;
        const interviewNo = item.interview_no || '<span class="tag gray">未生成</span>';
        const canReview = ['completed', 'report_ready'].includes(item.session_status) || ['completed', 'review_pending'].includes(item.candidate_status);
        const resumeAction = item.resume_name
          ? `<a href="./resume-view.html?candidate_id=${encodeURIComponent(item.id)}">查看简历</a>`
          : '<a class="disabled-link" href="javascript:void(0)">未上传简历</a>';
        const reviewAction = canReview
          ? `<a class="primary-link" href="./report.html?candidate_id=${encodeURIComponent(item.id)}">进入复核</a>`
          : '<a class="disabled-link" href="javascript:void(0)">未完成面试</a>';
        // session_status 为 completed 时，即使 candidate_status 未同步也显示已完成
        const effectiveStatus = (item.session_status === 'completed' && ['interviewing','pending_interview'].includes(item.candidate_status))
          ? 'completed' : item.candidate_status;
        tr.innerHTML = `
          <td>${typeof interviewNo === 'string' && interviewNo.includes('<span') ? interviewNo : esc(interviewNo)}</td>
          <td>${esc(candidateName)}</td>
          <td>${esc(item.phone || '-')}</td>
          <td>${esc(item.job_title || '-')}</td>
          <td>${resumeStatus}</td>
          <td><span class="tag blue">${esc(statusText(effectiveStatus))}</span></td>
          <td>${esc(realnameText(item.realname_status))}</td>
          <td>${esc(fmtTime(item.updated_at))}</td>
          <td><span class="table-actions"><a href="./report.html?candidate_id=${encodeURIComponent(item.id)}">查看详情</a>${resumeAction}${reviewAction}</span></td>`;
        rows.appendChild(tr);
      });
    }).catch((err) => {
      rows.innerHTML = `<tr><td colspan="9">${err.message}</td></tr>`;
    });
  }

  function setupReportReviewList() {
    if (page !== 'report-review-list.html') return;
    const rows = document.getElementById('reviewReportRows');
    const params = new URLSearchParams(location.search);
    const currentStatus = params.get('status') || 'pending';
    const count = document.getElementById('reviewCount');
    const continueCount = document.getElementById('reviewContinueCount');
    const holdCount = document.getElementById('reviewHoldCount');
    const rejectCount = document.getElementById('reviewRejectCount');
    const cards = Array.from(document.querySelectorAll('[data-review-filter]'));
    if (!rows) return;
    cards.forEach((card) => card.classList.toggle('active', card.dataset.reviewFilter === currentStatus));
    const titleMap = {pending: '待复核报告', continue: '已推进报告', hold: '待定报告', reject: '暂不推进报告', all: '全部报告'};
    const title = document.querySelector('.section-title h2');
    if (title) title.textContent = titleMap[currentStatus] || '待复核报告';
    get(`hr/list_review_reports.php?status=${encodeURIComponent(currentStatus)}`).then(({data}) => {
      rows.innerHTML = '';
      const reports = data.reports || [];
      const counts = data.counts || {};
      if (count) count.textContent = String(counts.pending || 0);
      if (continueCount) continueCount.textContent = String(counts.continue || 0);
      if (holdCount) holdCount.textContent = String(counts.hold || 0);
      if (rejectCount) rejectCount.textContent = String(counts.reject || 0);
      if (!reports.length) {
        rows.innerHTML = `<tr><td colspan="8">暂无${esc(titleMap[currentStatus] || '报告')}。</td></tr>`;
        return;
      }
      reports.forEach((item) => {
        const tr = document.createElement('tr');
        const candidateName = item.real_name || `候选人#${item.id}`;
        const reviewTextMap = {pending: '待复核', continue: '已推进', hold: '待定', reject: '暂不推进'};
        const reportStatus = item.report_id
          ? (item.report_status === 'reviewed' ? reviewTextMap[item.review_bucket] || '已复核' : '待复核')
          : '报告待生成';
        tr.innerHTML = `
          <td><input type="checkbox" class="row-cb" value="${esc(String(item.id))}" /></td>
          <td>${esc(item.interview_no || '-')}</td>
          <td>${esc(candidateName)}</td>
          <td>${esc(item.job_title || '-')}</td>
          <td>${item.resume_name ? `<a href="./resume-view.html?candidate_id=${encodeURIComponent(item.id)}">${esc(item.resume_name)}</a>` : '未上传'}</td>
          <td>${esc(reportStatus)}</td>
          <td>${esc(fmtTime(item.updated_at || item.interview_completed_at || item.report_updated_at))}</td>
          <td><span class="table-actions"><a class="primary-link" href="./report.html?candidate_id=${encodeURIComponent(item.id)}">进入复核</a></span></td>`;
        rows.appendChild(tr);
      });
    }).catch((err) => {
      rows.innerHTML = `<tr><td colspan="8">${err.message}</td></tr>`;
    });
  }
  function setupReportDetail() {
    if (page !== 'report.html') return;
    if (document.querySelector('.report-recording-list')) return;
    const params = new URLSearchParams(location.search);
    const candidateId = params.get('candidate_id');
    if (!candidateId) {
      const summary = document.getElementById('reportSummary');
      if (summary) summary.textContent = '缺少候选人参数，请从报告复核列表进入。';
      return;
    }
    get(`hr/report_detail.php?candidate_id=${encodeURIComponent(candidateId)}`).then(({data}) => {
      const candidate = data.candidate || {};
      const job = data.job || {};
      const session = data.session || {};
      const resume = data.resume || {};
      const report = data.report || {};
      const recordings = data.recordings || [];
      const name = candidate.real_name || `候选人#${candidate.id || candidateId}`;
      const interviewNo = session.interview_no || '面试编号未生成';
      const jobTitle = job.job_title || '-';
      const companyName = job.company_name || '-';
      const summary = report.summary || '候选人已完成语音初面，报告仍在整理中。HR可先查看题目和录音记录。';
      const keywords = (report.keywords || '').split(/[、,，\s]+/).filter(Boolean);

      const setText = (id, text) => {
        const el = document.getElementById(id);
        if (el) el.textContent = text;
      };
      setText('reportTitle', `${name}｜AI初面报告`);
      setText('reportSubtitle', `${companyName} · ${jobTitle}`);
      setText('reportInterviewNo', `面试编号：${interviewNo}`);
      setText('reportCandidateName', `${name}｜${jobTitle}`);
      setText('reportSummary', summary);
      setText('reportMatchScore', report.match_score || '-');
      setText('reportRecommendation', report.recommendation ? statusText(report.recommendation) : '待HR复核');
      setText('metaCandidate', name);
      setText('metaPhone', candidate.phone || '-');
      setText('metaJob', jobTitle);
      setText('metaCompany', companyName);
      setText('metaCompletedAt', fmtTime(session.completed_at || report.updated_at));
      setText('reportAiSummary', summary);

      const keywordBox = document.getElementById('metaKeywords');
      if (keywordBox) {
        keywordBox.innerHTML = keywords.length ? keywords.map((kw) => `<em>${kw}</em>`).join('') : '<em>待生成</em>';
      }

      const basicRows = document.getElementById('reportBasicRows');
      if (basicRows) {
        const resumeCell = resume && resume.original_name
          ? `<a href="./resume-view.html?candidate_id=${encodeURIComponent(candidateId)}">${esc(resume.original_name)}</a>`
          : '未上传';
        basicRows.innerHTML = `
          <tr><td>面试编号</td><td>${esc(interviewNo)}</td></tr>
          <tr><td>候选人姓名</td><td>${esc(name)}</td></tr>
          <tr><td>应聘岗位</td><td>${esc(companyName)} · ${esc(jobTitle)}</td></tr>
          <tr><td>工作地点</td><td>${esc(job.work_location || '未填写')}</td></tr><tr><td>薪资范围</td><td>${esc(formatAuthJobSalary(job))}</td></tr>
          <tr><td>简历材料</td><td>${resumeCell}</td></tr>
          <tr><td>录音数量</td><td>${recordings.length} 段</td></tr>`;
      }

      const recordingRows = document.getElementById('reportRecordingRows');
      if (recordingRows) {
        if (!recordings.length) {
          recordingRows.innerHTML = '<tr><td colspan="6">暂无语音记录。</td></tr>';
        } else {
          const transcriptText = (item) => {
            if (item.transcript_text) return item.transcript_text;
            if (item.transcript_status === 'completed') return '转写已完成，暂无文本内容';
            if (item.transcript_status === 'failed') return '转写失败，请稍后重试或联系平台处理';
            if (item.transcript_status === 'processing') return '转写处理中';
            return '等待语音转写服务处理';
          };
          const playBtnHtml = (item) => {
            if (!item.audio_object_key) return '<span class="tag gray">未保存</span>';
            return `<button type="button" class="btn play-recording-btn" data-recording-id="${esc(String(item.id || ''))}" style="padding:2px 10px;font-size:12px;">播放</button>`;
          };
          recordingRows.innerHTML = recordings.map((item, index) => `
            <tr>
              <td>${esc(item.sort_order || index + 1)}</td>
              <td>${esc(item.question_text || '-')}</td>
              <td>${esc(item.question_type || '-')}</td>
              <td>${item.audio_object_key ? '已保存' : '未保存'}</td>
              <td>${esc(transcriptText(item))}</td>
              <td>${playBtnHtml(item)}</td>
            </tr>`).join('');
          // 绑定播放按钮事件
          recordingRows.querySelectorAll('.play-recording-btn').forEach((btn) => {
            btn.addEventListener('click', async () => {
              const recId = btn.getAttribute('data-recording-id');
              if (!recId) return;
              // 检查是否已有播放器
              const existingAudio = btn.closest('tr')?.querySelector('.inline-audio-player');
              if (existingAudio) {
                existingAudio.remove();
                btn.textContent = '播放';
                return;
              }
              btn.textContent = '加载中...';
              btn.disabled = true;
              try {
                const res = await get(`hr/get_recording_url.php?recording_id=${encodeURIComponent(recId)}`);
                const audioUrl = res.data.url || '';
                if (!audioUrl) throw new Error('未获取到录音链接');
                const audio = document.createElement('audio');
                audio.className = 'inline-audio-player';
                audio.controls = true;
                audio.src = audioUrl;
                audio.style.cssText = 'width:100%;margin-top:6px;';
                const td = btn.closest('td');
                if (td) td.appendChild(audio);
                btn.textContent = '关闭';
                btn.disabled = false;
                audio.play().catch(() => {});
                audio.addEventListener('ended', () => { btn.textContent = '播放'; });
              } catch (err) {
                btn.textContent = '播放失败';
                btn.disabled = false;
                alert(err.message);
              }
            });
          });
        }
      }
      const decisionButtons = Array.from(document.querySelectorAll('.decision-options button'));
      const feedbackText = document.querySelector('#hr-feedback textarea');
      const saveButtons = Array.from(document.querySelectorAll('.feedback-actions .btn'));
      let selectedDecision = report.recommendation || '';
      const decisionMap = {continue: '继续推进', hold: '待定', reject: '暂不推进'};
      const applyDecision = () => {
        decisionButtons.forEach((btn) => {
          const value = Object.keys(decisionMap).find((key) => decisionMap[key] === btn.textContent.trim());
          btn.classList.toggle('active', value === selectedDecision);
        });
      };
      decisionButtons.forEach((btn) => {
        btn.addEventListener('click', () => {
          selectedDecision = Object.keys(decisionMap).find((key) => decisionMap[key] === btn.textContent.trim()) || '';
          applyDecision();
        });
      });
      applyDecision();
      const saveReview = async (asDraft = false) => {
        if (!asDraft && !selectedDecision) {
          alert('请先选择复核结果：继续推进、待定或暂不推进。');
          return;
        }
        try {
          await post('hr/save_report_review.php', {
            candidate_id: candidateId,
            recommendation: selectedDecision || 'hold',
            feedback: feedbackText ? feedbackText.value.trim() : '',
            draft: asDraft
          });
          alert(asDraft ? '复核意见已暂存' : '复核意见已提交');
          if (!asDraft) location.href = `./report-review-list.html?status=${encodeURIComponent(selectedDecision || 'hold')}`;
        } catch (err) {
          alert(err.message);
        }
      };
      if (saveButtons[0]) saveButtons[0].addEventListener('click', (e) => { e.preventDefault(); saveReview(true); });
      if (saveButtons[1]) saveButtons[1].addEventListener('click', (e) => { e.preventDefault(); saveReview(false); });
    }).catch((err) => {
      const summary = document.getElementById('reportSummary');
      if (summary) summary.textContent = err.message;
    });
  }
  function setupResumeView() {
    if (page !== 'resume-view.html') return;
    const params = new URLSearchParams(location.search);
    const candidateId = params.get('candidate_id');
    const set = (id, text) => {
      const el = document.getElementById(id);
      if (el) el.textContent = text || '-';
    };
    const fileName = document.getElementById('resumeFileName');
    const fileDesc = document.getElementById('resumeFileDesc');
    const previewBtn = document.getElementById('resumePreviewBtn');
    const downloadBtn = document.getElementById('resumeDownloadBtn');
    const paper = document.getElementById('resumePaper');
    if (!candidateId) {
      if (paper) paper.innerHTML = '<h2>缺少候选人参数</h2><p>请从候选人列表点击“查看简历”进入。</p>';
      return;
    }
    const renderPreview = (preview, name, job) => {
      if (!paper) return;
      const warn = preview.size_matches === false
        ? '<p class="hint danger">系统检测到文件大小与上传记录不一致，原文件可能异常。建议让候选人重新上传。</p>'
        : '';
      if (preview.preview_type === 'pdf') {
        paper.innerHTML = `
          <h2>简历预览</h2>
          ${warn}
          <iframe title="简历PDF预览" src="${esc(preview.inline_url)}" style="width:100%;min-height:680px;border:1px solid #d8e2ec;border-radius:8px;background:#fff;"></iframe>`;
        return;
      }
      if (preview.preview_type === 'text') {
        paper.innerHTML = `
          <h2>简历正文预览</h2>
          ${warn}
          <p class="hint">以下为系统从候选人上传的 DOCX 文件中提取的正文，排版可能与原文件不同，正式复核可下载原文件。</p>
          <pre style="white-space:pre-wrap;line-height:1.8;font-family:inherit;background:#fff;border:1px solid #d8e2ec;border-radius:8px;padding:18px;">${esc(preview.text || '未提取到可预览正文')}</pre>`;
        return;
      }
      paper.innerHTML = `
        <h2>暂不支持网页预览</h2>
        ${warn}
        <p>${esc(preview.message || '该文件格式暂时无法在网页中预览，请下载原文件查看。')}</p>
        <p><strong>候选人：</strong>${esc(name)}</p>
        <p><strong>应聘岗位：</strong>${esc(job.company_name || '-')} · ${esc(job.job_title || '-')}</p>
        <p><strong>文件名称：</strong>${esc(preview.original_name || '-')}</p>`;
    };
    get(`hr/resume_detail.php?candidate_id=${encodeURIComponent(candidateId)}`).then(async ({data}) => {
      const candidate = data.candidate || {};
      const job = data.job || {};
      const resume = data.resume || {};
      const name = candidate.real_name || `候选人#${candidate.id || candidateId}`;
      set('resumeName', name);
      set('resumeJob', job.job_title || '-');
      set('resumeUploadedAt', fmtTime(resume.created_at));
      set('resumeFormat', resume.file_ext ? resume.file_ext.toUpperCase() : '-');
      if (!resume.original_name) {
        if (fileName) fileName.textContent = '候选人暂未上传简历';
        if (fileDesc) fileDesc.textContent = '当前候选人没有可查看的简历文件。';
        if (previewBtn) previewBtn.classList.add('disabled-link');
        if (downloadBtn) downloadBtn.classList.add('disabled-link');
        if (paper) paper.innerHTML = '<h2>暂无简历</h2><p>候选人上传简历后，这里会展示文件信息和下载入口。</p>';
        return;
      }
      const url = `../api/hr/download_resume.php?candidate_id=${encodeURIComponent(candidateId)}`;
      if (fileName) fileName.textContent = resume.original_name;
      if (fileDesc) fileDesc.textContent = `文件大小：${resume.file_size_label || '-'}。该文件仅用于本次初面复核。`;
      if (previewBtn) {
        previewBtn.href = 'javascript:void(0)';
        previewBtn.target = '';
      }
      if (downloadBtn) {
        downloadBtn.href = 'javascript:void(0)';
        downloadBtn.onclick = (event) => {
          event.preventDefault();
          if (!window.confirm('是否下载候选人原始简历？该文件仅用于本次招聘复核。')) return;
          window.location.href = `${url}&download=1`;
        };
      }
      if (paper) {
        paper.innerHTML = `
          <h2>正在生成预览</h2>
          <p><strong>候选人：</strong>${esc(name)}</p>
          <p><strong>应聘岗位：</strong>${esc(job.company_name || '-')} · ${esc(job.job_title || '-')}</p>
          <p><strong>文件名称：</strong>${esc(resume.original_name)}</p>
          <p><strong>上传时间：</strong>${esc(fmtTime(resume.created_at))}</p>
          <p><strong>系统提示：</strong>AI会基于这份简历匹配追问问题，HR可在面试报告中复核问题质量。</p>`;
      }
      const previewRes = await get(`hr/resume_preview.php?candidate_id=${encodeURIComponent(candidateId)}`);
      const preview = previewRes.data.preview || {};
      renderPreview(preview, name, job);
      if (previewBtn) {
        previewBtn.addEventListener('click', (e) => {
          e.preventDefault();
          renderPreview(preview, name, job);
          paper?.scrollIntoView({behavior: 'smooth', block: 'start'});
        });
      }
    }).catch((err) => {
      if (paper) paper.innerHTML = `<h2>简历读取失败</h2><p>${esc(err.message)}</p>`;
    });
  }
  function setupInviteLink() {
    if (page !== 'link.html') return;
    const params = new URLSearchParams(location.search);
    const jobId = params.get('job_id');
    const linkText = document.querySelector('.link-box span');
    const title = document.querySelector('.page-title h1');
    if (!jobId) {
      if (linkText) linkText.textContent = '请先从初面管理页选择岗位生成链接';
      return;
    }
    post('hr/create_invite.php', {job_id: jobId}).then(({data}) => {
      if (title) title.textContent = '面试链接已生成';
      if (linkText) linkText.textContent = data.link;
    }).catch((err) => {
      if (linkText) linkText.textContent = err.message;
    });
  }
  function questionCard(item) {
    const article = document.createElement('article');
    article.className = 'pool-question-card';
    const required = Number(item.is_required) ? '必答' : '备选';
    article.innerHTML = `
      <div><strong>${item.question_text || item.question || ''}</strong></div>
      <p class="hint">${item.question_type || item.type || '岗位题'} · ${item.difficulty || '中等'} · ${item.purpose || '用于初面判断'}</p>
      <div class="question-actions"><a>${required}</a><a>加入匹配库</a><a>加入备选</a></div>`;
    return article;
  }
  function renderQuestions(items) {
    const list = document.getElementById('aiQuestionList');
    if (!list) return;
    list.innerHTML = '';
    if (!items || items.length === 0) {
      list.innerHTML = '<article class="pool-question-card"><div><strong>暂无AI题目，请先点击生成题库初稿。</strong></div></article>';
      return;
    }
    items.forEach(item => list.appendChild(questionCard(item)));
  }
  function setupQuestionBank() {
    if (page !== 'question-bank.html') return;
    const params = new URLSearchParams(location.search);
    let jobId = params.get('job_id');
    const button = document.getElementById('generateAiQuestions');
    const title = document.querySelector('[data-job-title]');
    const meta = document.querySelector('[data-job-meta]');
    const showBankMessage = (text, ok = false) => {
      const list = document.getElementById('aiQuestionList');
      if (!list) return;
      list.innerHTML = `<article class="pool-question-card"><div><strong>${text}</strong></div></article>`;
      list.classList.toggle('ok', ok);
    };
    if (button) button.disabled = false;
    const loadJob = () => get(`hr/job_detail.php?job_id=${encodeURIComponent(jobId)}`).then(({data}) => {
      const job = data.job || {};
      if (title) title.textContent = job.job_title || '当前岗位';
      if (meta) meta.textContent = `${formatAuthJobLocation(job)}｜${formatAuthJobSalary(job)}｜全职｜${job.benefits || '福利待补充'}`;
    });
    const loadQuestions = () => get(`ai/list_questions.php?job_id=${encodeURIComponent(jobId)}`).then(({data}) => {
      if (data.questions && data.questions.length) renderQuestions(data.questions);
      else showBankMessage('暂无AI题目，请点击“AI生成题库初稿”。');
    });
    const init = async () => {
      if (!jobId) {
        showBankMessage('正在读取岗位信息...');
        const jobs = await get('hr/list_jobs.php');
        const first = jobs.data.jobs && jobs.data.jobs[0];
        if (!first) {
          showBankMessage('还没有岗位。请先创建岗位，再生成AI题库。');
          if (button) {
            button.textContent = '请先创建岗位';
            button.disabled = true;
          }
          return;
        }
        jobId = String(first.id);
        history.replaceState(null, '', `question-bank.html?job_id=${encodeURIComponent(jobId)}`);
      }
      await loadJob().catch((err) => showBankMessage(err.message));
      await loadQuestions().catch((err) => showBankMessage(err.message));
    };
    init().catch((err) => showBankMessage(err.message));
    button?.addEventListener('click', async () => {
      if (!jobId) {
        showBankMessage('还没有可用岗位，请先创建岗位。');
        return;
      }
      button.textContent = 'AI生成中...';
      button.disabled = true;
      button.style.pointerEvents = 'none';
      showBankMessage('AI正在生成题库初稿，请稍等...');
      try {
        const res = await post('ai/generate_questions.php', {job_id: jobId});
        renderQuestions((res.data.questions || []).map((q, i) => ({
          question_text: q.question,
          question_type: q.type,
          difficulty: q.difficulty,
          purpose: q.purpose,
          is_required: q.is_required ? 1 : 0,
          sort_order: i + 1
        })));
        button.textContent = '重新生成题库初稿';
      } catch (err) {
        showBankMessage(err.message);
        button.textContent = 'AI生成题库初稿';
      } finally {
        button.disabled = false;
        button.style.pointerEvents = '';
      }
    });
  }
  function setupProfile() {
    if (page !== 'profile.html') return;
    const profileForm = document.getElementById('profileForm');
    const passwordForm = document.getElementById('passwordForm');
    const companyForm = document.getElementById('companyForm');
    get('auth/me.php').then(({data}) => {
      fillForm(profileForm, data);
      const name = document.getElementById('miniName');
      const status = document.getElementById('miniStatus');
      const avatar = document.getElementById('avatarText');
      if (name) name.textContent = data.name || 'HR用户';
      if (status) status.textContent = `${data.email_verified ? '邮箱已绑定' : '邮箱未绑定'} · ${data.realname_status === 'verified' ? '已实名' : '未实名'}`;
      if (avatar) avatar.textContent = (data.name || 'HR').slice(0, 2).toUpperCase();
      const phoneTag = document.getElementById('profilePhoneTag');
      const phoneText = document.getElementById('profilePhoneText');
      const emailTag = document.getElementById('profileEmailTag');
      const emailText = document.getElementById('profileEmailText');
      const realnameTag = document.getElementById('profileRealnameTag');
      const realnameTextEl = document.getElementById('profileRealnameText');
      const realnameStatus = document.getElementById('profileRealnameStatus');
      const companyTag = document.getElementById('profileCompanyTag');
      const companyText = document.getElementById('profileCompanyText');
      if (phoneTag) { phoneTag.textContent = data.phone_verified ? '已验证' : '未验证'; phoneTag.className = `tag ${data.phone_verified ? 'green' : 'gray'}`; }
      if (phoneText) phoneText.textContent = data.phone || '-';
      if (emailTag) { emailTag.textContent = data.email_verified ? '已绑定' : '未绑定'; emailTag.className = `tag ${data.email_verified ? 'green' : 'gray'}`; }
      if (emailText) emailText.textContent = data.email || '-';
      const isReal = data.realname_status === 'verified';
      if (realnameTag) { realnameTag.textContent = isReal ? '已实名' : '未实名'; realnameTag.className = `tag ${isReal ? 'green' : 'gray'}`; }
      if (realnameTextEl) realnameTextEl.textContent = isReal ? '已完成HR本人实名核验' : '未完成实名认证';
      if (realnameStatus) realnameStatus.textContent = isReal ? '已实名' : '未实名';
      const companyVerified = data.company_verification_status === 'verified';
      if (companyTag) { companyTag.textContent = companyVerified ? '已认证' : '待认证'; companyTag.className = `tag ${companyVerified ? 'green' : 'orange'}`; }
      if (companyText) companyText.textContent = companyVerified ? '企业认证已完成' : '认证后展示企业资料';
    }).catch(() => {});
    profileForm?.addEventListener('submit', async (e) => {
      e.preventDefault();
      try { const res = await post('auth/update_profile.php', formPayload(profileForm)); message(profileForm, res.message, true); }
      catch (err) { message(profileForm, err.message); }
    });
    passwordForm?.addEventListener('submit', async (e) => {
      e.preventDefault();
      try { const res = await post('auth/reset_password.php', formPayload(passwordForm)); message(passwordForm, res.message, true); passwordForm.reset(); }
      catch (err) { message(passwordForm, err.message); }
    });
    companyForm?.addEventListener('submit', async (e) => {
      e.preventDefault();
      try { const res = await post('auth/save_company.php', formPayload(companyForm)); message(companyForm, res.message, true); }
      catch (err) { message(companyForm, err.message); }
    });
  }

  function setupLogout() {
    document.querySelectorAll('a[href="./login.html"]').forEach(a => {
      if ((a.textContent || '').includes('退出')) {
        a.addEventListener('click', async (e) => {
          e.preventDefault();
          try { await post('auth/logout.php', {}); }
          finally { location.href = './login.html'; }
        });
      }
    });
  }

  setupRegister(); setupEmail(); setupRealname(); setupLogin(); setupProfile(); setupCreateJob(); setupJobPreview(); setupJobManagement(); setupCandidateList(); setupReportReviewList(); setupReportDetail(); setupResumeView(); setupInviteLink(); setupQuestionBank(); setupLogout();
  guard().then(() => {
    updateDashboardStatus();
    setupDashboardData();
    setupMembershipBadge();
  });
})();
