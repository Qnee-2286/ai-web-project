(() => {
  const API = '../api';
  const page = location.pathname.split('/').pop() || 'index.html';
  const params = new URLSearchParams(location.search);
  const speechRecognition = window.SpeechRecognition || window.webkitSpeechRecognition;
  let entryLogoutPromise = null;

  const $ = (sel, root = document) => root.querySelector(sel);
  const $$ = (sel, root = document) => Array.from(root.querySelectorAll(sel));
  const urlToken = () => params.get('token') || params.get('invite_token') || params.get('code') || '';
  const token = () => {
    const fromUrl = urlToken();
    if (fromUrl) return fromUrl;
    const candidateToken = localStorage.getItem('candidate_token') || '';
    const inviteToken = localStorage.getItem('candidate_invite_token') || '';
    if (page === 'index.html') return '';
    if (page === 'auth.html' && candidateToken) {
      return candidateToken;
    }
    if (page === 'login.html' || page === 'auth.html') {
      return inviteToken;
    }
    return candidateToken;
  };


  function formatJobSalary(job) {
    const unit = job.salary_unit || 'K/月';
    if (unit === '面议') return '面议';
    const min = String(job.salary_min_k || '').trim();
    const max = String(job.salary_max_k || '').trim();
    if (!min || !max) return '薪资以企业填写为准';
    return unit === '元/天' ? (min + '-' + max + '元/天') : (min + '-' + max + 'K/月');
  }

  function formatJobMeta(job) {
    return [job.work_location, formatJobSalary(job), job.benefits || '福利以企业填写为准'].filter(Boolean).join('｜');
  }

  const localDate = () => {
    const now = new Date();
    const yyyy = now.getFullYear();
    const mm = String(now.getMonth() + 1).padStart(2, '0');
    const dd = String(now.getDate()).padStart(2, '0');
    return `${yyyy}-${mm}-${dd}`;
  };

  function clearCandidateLogin(keepInvite = true) {
    const invite = keepInvite ? (localStorage.getItem('candidate_invite_token') || params.get('token') || '') : '';
    localStorage.removeItem('candidate_token');
    localStorage.removeItem('candidate_login_date');
    localStorage.removeItem('candidate_account_id');
    if (invite) localStorage.setItem('candidate_invite_token', invite);
    else localStorage.removeItem('candidate_invite_token');
  }

  function rememberCandidateLogin(candidateToken, inviteToken = '') {
    if (!candidateToken) return;
    localStorage.setItem('candidate_token', candidateToken);
    localStorage.setItem('candidate_login_date', localDate());
    if (inviteToken) localStorage.setItem('candidate_invite_token', inviteToken);
  }

  function enforceDailyCandidateLogin() {
    const candidateToken = localStorage.getItem('candidate_token');
    if (!candidateToken) return;
    const loginDate = localStorage.getItem('candidate_login_date');
    if (loginDate !== localDate()) {
      localStorage.removeItem('candidate_token');
      localStorage.removeItem('candidate_login_date');
    }
  }

  function showMessage(container, text, ok = false) {
    const root = container || document.body;
    let box = root.querySelector('.form-message');
    if (!box) {
      box = document.createElement('div');
      box.className = 'form-message';
      root.prepend(box);
    }
    box.textContent = text;
    box.classList.toggle('ok', ok);
  }

  function escapeHtml(text) {
    return String(text ?? '').replace(/[&<>"']/g, (ch) => ({
      '&': '&amp;',
      '<': '&lt;',
      '>': '&gt;',
      '"': '&quot;',
      "'": '&#39;',
    }[ch]));
  }

  function errorDetail(data) {
    const msg = data.message || '请求失败';
    const detail = (data.data && data.data.error) ? (' (' + data.data.error + ')') : '';
    return msg + detail;
  }

  async function post(path, payload) {
    const res = await fetch(`${API}/${path}`, {
      method: 'POST',
      headers: { 'Content-Type': 'application/json' },
      credentials: 'include',
      body: JSON.stringify(payload),
    });
    const data = await res.json().catch(() => ({ ok: false, message: '接口返回格式错误' }));
    if (!res.ok || !data.ok) throw new Error(errorDetail(data));
    return data;
  }

  async function postForm(path, formData) {
    const res = await fetch(`${API}/${path}`, {
      method: 'POST',
      credentials: 'include',
      body: formData,
    });
    const data = await res.json().catch(() => ({ ok: false, message: '接口返回格式错误' }));
    if (!res.ok || !data.ok) throw new Error(data.message || '请求失败');
    return data;
  }

  async function get(path) {
    const res = await fetch(`${API}/${path}`, { credentials: 'include' });
    const data = await res.json().catch(() => ({ ok: false, message: '接口返回格式错误' }));
    if (!res.ok || !data.ok) throw new Error(data.message || '请求失败');
    return data;
  }

  function prepareInviteEntrySession() {
    const entryPages = new Set(['index.html', 'login.html', 'auth.html']);
    const t = urlToken();
    if (!entryPages.has(page) || !t) return Promise.resolve();

    localStorage.removeItem('candidate_token');
    localStorage.removeItem('candidate_login_date');
    localStorage.setItem('candidate_invite_token', t);

    if (!entryLogoutPromise) {
      entryLogoutPromise = post('candidate/logout.php', {}).catch(() => {});
    }
    return entryLogoutPromise;
  }

  function withToken(url) {
    const t = token();
    if (!t) return url;
    const next = new URL(url, location.href);
    next.searchParams.set('token', t);
    return `${next.pathname.split('/').pop()}${next.search}`;
  }

  function keepTokenOnLinks() {
    const t = token();
    if (!t) return;
    $$('a[href]').forEach((a) => {
      const href = a.getAttribute('href') || '';
      if (!href || href.startsWith('#') || href.startsWith('javascript:') || href.includes('agreement.html')) return;
      if (!href.endsWith('.html') && !href.includes('.html?')) return;
      a.setAttribute('href', withToken(href));
    });
  }

  function addSendCodeButton(codeInput, phoneInput, purpose = 'candidate_auth') {
    if (!codeInput || !phoneInput || codeInput.dataset.codeButtonAdded) return;
    codeInput.dataset.codeButtonAdded = '1';
    const btn = document.createElement('button');
    btn.type = 'button';
    btn.className = 'inline-code-button';
    btn.textContent = '发送验证码';
    codeInput.insertAdjacentElement('afterend', btn);

    const reset = () => {
      window.clearInterval(btn._codeTimer);
      btn.disabled = false;
      btn.textContent = '重新发送';
    };

    const cooldown = () => {
      let left = 60;
      window.clearInterval(btn._codeTimer);
      btn.disabled = true;
      btn.textContent = `重新发送(${left}s)`;
      btn._codeTimer = window.setInterval(() => {
        left -= 1;
        if (left <= 0) {
          reset();
          return;
        }
        btn.textContent = `重新发送(${left}s)`;
      }, 1000);
    };

    btn.addEventListener('click', async () => {
      const phone = phoneInput.value.trim();
      if (!/^1[3-9]\d{9}$/.test(phone)) {
        alert('请先填写正确的手机号');
        return;
      }
      btn.disabled = true;
      btn.textContent = '发送中...';
      try {
        const res = await post('auth/send_sms_code.php', { phone, purpose });
        if (res.data && res.data.dev_code) codeInput.value = res.data.dev_code;
        cooldown();
      } catch (err) {
        alert(err.message);
        reset();
      }
    });
  }

  async function goNextByStatus(t) {
    const res = await get(`candidate/status.php?token=${encodeURIComponent(t)}`);
    const data = res.data || {};
    if (!data.phone_verified || data.realname_status !== 'verified') {
      location.href = `auth.html?token=${encodeURIComponent(t)}`;
      return;
    }
    if (['device_checked', 'interviewing'].includes(data.latest_session_status || '')) {
      location.href = `interview.html?token=${encodeURIComponent(t)}`;
      return;
    }
    if (data.candidate_status === 'completed' || data.latest_session_status === 'completed') {
      const sid = data.completed_session_id || data.latest_session_id || '';
      const session = sid ? `&session_id=${encodeURIComponent(sid)}` : '';
      location.href = `complete.html?token=${encodeURIComponent(t)}${session}`;
      return;
    }
    if (!data.resume_uploaded) {
      location.href = `resume.html?token=${encodeURIComponent(t)}`;
      return;
    }
    location.href = `job-confirm.html?token=${encodeURIComponent(t)}`;
  }

  async function continueAfterPhoneVerified(candidateToken, showRealnameStep) {
    try {
      const res = await get(`candidate/status.php?token=${encodeURIComponent(candidateToken)}`);
      const data = res.data || {};
      if (data.realname_status === 'verified') {
        if (['device_checked', 'interviewing'].includes(data.latest_session_status || '')) {
          location.href = `interview.html?token=${encodeURIComponent(candidateToken)}`;
          return;
        }
        if (data.candidate_status === 'completed' || data.latest_session_status === 'completed') {
          const sid = data.completed_session_id || data.latest_session_id || '';
          const session = sid ? `&session_id=${encodeURIComponent(sid)}` : '';
          location.href = `complete.html?token=${encodeURIComponent(candidateToken)}${session}`;
          return;
        }
        if (!data.resume_uploaded) {
          location.href = `resume.html?token=${encodeURIComponent(candidateToken)}`;
          return;
        }
        location.href = `job-confirm.html?token=${encodeURIComponent(candidateToken)}`;
        return;
      }
    } catch (_) {}
    showRealnameStep();
  }

  async function resumeAuthPageIfPossible(currentToken, showRealnameStep) {
    if (!currentToken) return;
    try {
      const res = await get(`candidate/status.php?token=${encodeURIComponent(currentToken)}`);
      const data = res.data || {};
      if (!data.phone_verified) return;
      rememberCandidateLogin(currentToken, localStorage.getItem('candidate_invite_token') || '');
      if (data.realname_status !== 'verified') {
        showRealnameStep();
        return;
      }
      if (['device_checked', 'interviewing'].includes(data.latest_session_status || '')) {
        location.href = `interview.html?token=${encodeURIComponent(currentToken)}`;
        return;
      }
      if (data.candidate_status === 'completed' || data.latest_session_status === 'completed') {
        const sid = data.completed_session_id || data.latest_session_id || '';
        const session = sid ? `&session_id=${encodeURIComponent(sid)}` : '';
        location.href = `complete.html?token=${encodeURIComponent(currentToken)}${session}`;
        return;
      }
      if (!data.resume_uploaded) {
        location.href = `resume.html?token=${encodeURIComponent(currentToken)}`;
        return;
      }
      location.href = `job-confirm.html?token=${encodeURIComponent(currentToken)}`;
    } catch (_) {}
  }

  function setupInvite() {
    if (page !== 'index.html') return;
    const t = urlToken();
    if (t) {
      prepareInviteEntrySession();
    }
    const panel = $('#jobInfoPanel') || $('.info-list');
    const startLink = $('#startInterview');
    if (startLink && t) startLink.setAttribute('href', `./auth.html?token=${encodeURIComponent(t)}`);
    if (t) {
      localStorage.setItem('candidate_invite_token', t);
      get(`candidate/job_detail.php?token=${encodeURIComponent(t)}`).then(({ data }) => {
        const job = data.job || {};
        const values = {
          company_name: job.company_name,
          job_title: job.job_title,
          salary_range: formatJobSalary(job),
          benefits: job.benefits || '以企业HR填写内容为准',
        };
        Object.entries(values).forEach(([key, value]) => {
          const el = document.querySelector(`[data-job="${key}"]`);
          if (el) el.textContent = value || '未填写';
        });
      }).catch((err) => showMessage(panel, err.message));
    }

    $('#startInterview')?.addEventListener('click', async (event) => {
      event.preventDefault();
      if (!t) {
        showMessage(document.body, '面试链接缺少专属面试码，请从HR发送的邀请链接重新进入');
        return;
      }
      showMessage(document.body, '请先登录，登录后会自动继续本场面试。', true);
      try {
        await prepareInviteEntrySession();
        location.href = `auth.html?token=${encodeURIComponent(t)}`;
      } catch {
        location.href = `auth.html?token=${encodeURIComponent(t)}`;
      }
    });
  }

  function setupCandidateSessionActions() {
    const sessionPages = new Set([
      'resume.html',
      'job-confirm.html',
      'device-check.html',
      'interview.html',
      'processing.html',
      'complete.html',
    ]);
    if (!sessionPages.has(page)) return;
    const currentToken = token();
    const inviteToken = localStorage.getItem('candidate_invite_token') || '';
    const top = $('.top');
    if (!top || $('#candidateSwitchBtn')) return;

    const actions = document.createElement('div');
    actions.className = 'candidate-session-actions';
    actions.innerHTML = `
      <button class="btn ghost" id="candidateSwitchBtn" type="button">更换候选人</button>
      <button class="btn ghost" id="candidateLogoutBtn" type="button">退出</button>
    `;
    top.appendChild(actions);

    $('#candidateSwitchBtn')?.addEventListener('click', async () => {
      clearCandidateLogin(true);
      try {
        await post('candidate/logout.php', {});
      } catch (_) {}
      location.href = inviteToken ? `auth.html?token=${encodeURIComponent(inviteToken)}` : 'index.html';
    });

    $('#candidateLogoutBtn')?.addEventListener('click', async () => {
      clearCandidateLogin(false);
      try {
        await post('candidate/logout.php', {});
      } catch (_) {}
      location.href = 'index.html';
    });
  }

  function setupLogin() {
    if (page !== 'login.html') return;
    // 判断是否在面试流程中（URL 带 from=interview 或 token）
    const inInterviewFlow = params.get('from') === 'interview' || urlToken();
    if (inInterviewFlow) {
      prepareInviteEntrySession();
    }
    const form = $('form');
    if (!form) return;
    const phoneInput = $('input[name="phone"]', form);
    const codeInput = $('input[name="sms_code"]', form);
    const loginBtn = $('#candidateLoginBtn');
    const t = token();

    // 页面没有自带验证码按钮时动态添加（面试流程和普通登录均注入）
    if (!form.querySelector('.sms-btn') && !form.querySelector('.inline-code-button')) {
      addSendCodeButton(codeInput, phoneInput);
    }

    // 只有面试流程中才提示缺少面试码
    if (inInterviewFlow && !t) {
      showMessage(form, '面试链接缺少专属面试码，请从HR发送的邀请链接重新进入');
    }

    loginBtn?.addEventListener('click', async (event) => {
      event.preventDefault();
      try {
        loginBtn.textContent = '验证中...';
        loginBtn.disabled = true;

        if (!inInterviewFlow) {
          /* ---- 非面试流程（从产品主页"我是求职者"进入）→ 独立登录 ---- */
          const loginRes = await post('candidate/login.php', {
            phone: phoneInput.value.trim(),
            sms_code: codeInput.value.trim(),
          });
          /* 登录成功：存 account_id，跳个人中心 */
          if (loginRes.data && loginRes.data.account_id) {
            localStorage.setItem('candidate_account_id', String(loginRes.data.account_id));
          }
          location.href = 'profile.html';
        } else {
          /* ---- 面试流程 → 走原有 verify_phone.php ---- */
          const verified = await post('candidate/verify_phone.php', {
            token: t,
            phone: phoneInput.value.trim(),
            sms_code: codeInput.value.trim(),
            agreement: true,
            job_record_confirm: true,
          });
          const candidateToken = verified.data.candidate_token;
          if (verified.data && verified.data.candidate_account_id) {
            localStorage.setItem('candidate_account_id', String(verified.data.candidate_account_id));
          }
        rememberCandidateLogin(candidateToken, t);
        await continueAfterPhoneVerified(candidateToken, () => {
            location.href = 'auth.html';
        });
        }
      } catch (err) {
        loginBtn.textContent = inInterviewFlow ? '验证手机号，继续下一步' : '登录';
        loginBtn.disabled = false;
        showMessage(form, err.message);
      }
    });
  }

  function setupAuth() {
    if (page !== 'auth.html') return;
    prepareInviteEntrySession();
    const step1 = $('#authStep1');
    const step2 = $('#authStep2');
    const phoneForm = $('#phoneForm');
    const phoneInput = $('input[name="phone"]', phoneForm);
    const codeInput = $('input[name="sms_code"]', phoneForm);
    const agreeCheck1 = $('#agreeCheck1');
    const agreeCheck2 = $('#agreeCheck2');
    const verifyPhoneBtn = $('#verifyPhoneBtn');
    const realnameForm = $('#realnameForm');
    const nameInput = $('input[name="real_name"]', realnameForm);
    const idCardInput = $('input[name="id_card"]', realnameForm);
    const realnameBtn = $('#realnameBtn');
    const t = token();
    const showRealnameStep = () => {
      if (step1) step1.style.display = 'none';
      if (step2) step2.style.display = '';
      showMessage(realnameForm, '手机号验证成功，请继续完成实名认证。', true);
    };

    if (!t) {
      showMessage(phoneForm, '面试链接缺少专属面试码，请从HR发送的邀请链接重新进入');
    }

    addSendCodeButton(codeInput, phoneInput);

    const existingCandidateToken = localStorage.getItem('candidate_token') || '';
    if (existingCandidateToken && !urlToken()) {
      continueAfterPhoneVerified(existingCandidateToken, showRealnameStep);
    } else if (t) {
      resumeAuthPageIfPossible(t, showRealnameStep);
    }

    // 第1步：验证手机号
    verifyPhoneBtn?.addEventListener('click', async (event) => {
      event.preventDefault();
      if (!agreeCheck1?.checked || !agreeCheck2?.checked) {
        showMessage(phoneForm, '请先勾选同意授权协议和确认声明');
        return;
      }
      try {
        verifyPhoneBtn.textContent = '验证中...';
        verifyPhoneBtn.disabled = true;
        const verified = await post('candidate/verify_phone.php', {
          token: t,
          phone: phoneInput.value.trim(),
          sms_code: codeInput.value.trim(),
          agreement: true,
          job_record_confirm: true,
        });
        const candidateToken = verified.data.candidate_token;
        if (verified.data && verified.data.candidate_account_id) {
          localStorage.setItem('candidate_account_id', String(verified.data.candidate_account_id));
        }
        rememberCandidateLogin(candidateToken, t);
        await continueAfterPhoneVerified(candidateToken, showRealnameStep);
      } catch (err) {
        verifyPhoneBtn.textContent = '验证手机号并继续';
        verifyPhoneBtn.disabled = false;
        showMessage(phoneForm, err.message);
      }
    });

    // 第2步：实名认证
    realnameBtn?.addEventListener('click', async (event) => {
      event.preventDefault();
      const candidateToken = localStorage.getItem('candidate_token') || t;
      try {
        realnameBtn.textContent = '认证中...';
        realnameBtn.disabled = true;
        const realname = await post('candidate/realname.php', {
          token: candidateToken,
          provider: 'wechat',
          real_name: nameInput.value.trim(),
          id_card: idCardInput.value.trim(),
        });
        if (realname.data.redirect_url) {
          location.href = realname.data.redirect_url;
          return;
        }
        location.href = realname.data.next || `resume.html?token=${encodeURIComponent(candidateToken)}`;
      } catch (err) {
        realnameBtn.textContent = '通过微信完成实名认证';
        realnameBtn.disabled = false;
        showMessage(realnameForm, err.message);
      }
    });
  }

  function setupResume() {
    if (page !== 'resume.html') return;
    const form = $('.resume-form');
    if (!form) return;
    const fileInput = $('#resume-file', form);
    const fileLabel = $('.selected-file', form);
    const primary = $('.btn.primary');
    const t = token();
    if (!t) {
      showMessage(form, '候选人链接无效，请返回认证页面重新进入');
      return;
    }

    get(`candidate/resume_status.php?token=${encodeURIComponent(t)}`).then((res) => {
      if (res.data.realname_status !== 'verified') {
        showMessage(form, '请先完成实名认证，再上传简历');
      }
      if (res.data.uploaded && res.data.resume && fileLabel) {
        fileLabel.textContent = `已上传：${res.data.resume.original_name}`;
      }
    }).catch((err) => showMessage(form, err.message));

    fileInput?.addEventListener('change', () => {
      const file = fileInput.files && fileInput.files[0];
      if (fileLabel) fileLabel.textContent = file ? `已选择：${file.name}` : '尚未选择文件';
    });

    primary?.addEventListener('click', async (event) => {
      event.preventDefault();
      const file = fileInput.files && fileInput.files[0];
      if (!file) {
        showMessage(form, '请先选择简历文件');
        return;
      }
      const data = new FormData();
      data.append('token', t);
      data.append('resume', file);
      primary.textContent = '上传中...';
      primary.classList.add('disabled');
      try {
        const res = await fetch(`${API}/candidate/upload_resume.php`, { method: 'POST', credentials: 'include', body: data });
        const body = await res.json().catch(() => ({ ok: false, message: '接口返回格式错误' }));
        if (!res.ok || !body.ok) throw new Error(body.message || '上传失败');
        showMessage(form, '简历上传成功，正在进入岗位确认...', true);
        location.href = body.data.next || `job-confirm.html?token=${encodeURIComponent(t)}`;
      } catch (err) {
        primary.textContent = '上传并下一步';
        primary.classList.remove('disabled');
        showMessage(form, err.message);
      }
    });
  }

  function setupJobConfirm() {
    if (page !== 'job-confirm.html') return;
    const panel = $('#jobInfoPanel');
    const t = token();
    if (!panel || !t) return;
    get(`candidate/job_detail.php?token=${encodeURIComponent(t)}`).then(({ data }) => {
      const job = data.job || {};
      const put = (key, text) => {
        const el = panel.querySelector(`[data-job="${key}"]`);
        if (el) el.textContent = text || '未填写';
      };
      put('company_name', job.company_name);
      put('job_title', job.job_title);
      put('salary_benefits', formatJobMeta(job));
      put('company_intro', job.company_intro);
      put('responsibilities', job.responsibilities);
      put('requirements', job.requirements);
    }).catch((err) => {
      showMessage(panel, err.message);
      $('.btn.primary')?.classList.add('disabled');
    });
  }

  function setupDeviceCheck() {
    if (page !== 'device-check.html') return;
    const panel = $('.panel');
    const primary = $('#deviceNextBtn');
    const startBtn = $('#startDeviceCheckBtn');
    const preview = $('#devicePreview');
    const video = $('#deviceCameraPreview');
    const micBar = $('#micMeterBar');
    const micMessage = $('#micTestMessage');
    const t = token();
    const cards = $$('.device-card');
    let mediaStream = null;
    let audioContext = null;
    let audioAnimation = 0;
    let audioSeen = false;

    const setCard = (index, text, status = '') => {
      const card = cards[index];
      if (!card) return;
      const span = $('span', card);
      if (span) span.textContent = text;
      card.classList.toggle('ok', status === 'ok');
      card.classList.toggle('error', status === 'error');
    };

    const setNextEnabled = (enabled) => {
      primary?.classList.toggle('disabled', !enabled);
      primary?.setAttribute('aria-disabled', enabled ? 'false' : 'true');
    };

    const stopStream = () => {
      window.cancelAnimationFrame(audioAnimation);
      audioAnimation = 0;
      if (audioContext) {
        audioContext.close().catch(() => {});
        audioContext = null;
      }
      mediaStream?.getTracks().forEach((track) => track.stop());
      mediaStream = null;
      if (video) video.srcObject = null;
    };

    const permissionMessage = (err) => {
      if (err && (err.name === 'NotAllowedError' || err.name === 'PermissionDeniedError')) {
        return '设备权限未开启。请在浏览器设置中允许摄像头和麦克风，再重新检测。';
      }
      if (err && (err.name === 'NotFoundError' || err.name === 'DevicesNotFoundError')) {
        return '没有检测到可用的摄像头或麦克风，请检查手机设备后重试。';
      }
      if (err && (err.name === 'NotReadableError' || err.name === 'TrackStartError')) {
        return '摄像头或麦克风正在被其他应用占用，请关闭其他通话或录制功能后重试。';
      }
      return (err && err.message) || '设备权限获取失败，请允许摄像头和麦克风权限。';
    };

    const monitorMicrophone = async (stream) => {
      const Context = window.AudioContext || window.webkitAudioContext;
      if (!Context) {
        if (micMessage) micMessage.textContent = '麦克风已授权。';
        return;
      }
      audioContext = new Context();
      await audioContext.resume();
      const analyser = audioContext.createAnalyser();
      analyser.fftSize = 256;
      const source = audioContext.createMediaStreamSource(stream);
      source.connect(analyser);
      const samples = new Uint8Array(analyser.frequencyBinCount);

      const draw = () => {
        analyser.getByteFrequencyData(samples);
        const peak = Math.max(...samples);
        const level = Math.min(100, Math.max(2, Math.round((peak / 255) * 140)));
        if (micBar) micBar.style.width = `${level}%`;
        if (peak > 12 && !audioSeen) {
          audioSeen = true;
          if (micMessage) micMessage.textContent = '已检测到你的声音，麦克风可正常使用。';
        }
        audioAnimation = window.requestAnimationFrame(draw);
      };
      draw();
    };

    async function checkDevices() {
      setNextEnabled(false);
      stopStream();
      audioSeen = false;
      if (micBar) micBar.style.width = '2%';
      if (micMessage) micMessage.textContent = '请说一句话，确认音量条会变化。';
      setCard(0, '正在申请权限...');
      setCard(1, '正在申请权限...');
      setCard(2, navigator.onLine ? '网络正常' : '网络异常', navigator.onLine ? 'ok' : 'error');
      try {
        if (!navigator.mediaDevices || !navigator.mediaDevices.getUserMedia) {
          throw new Error('当前浏览器不支持设备检测，请复制链接到手机系统浏览器中打开。');
        }
        mediaStream = await navigator.mediaDevices.getUserMedia({
          video: { facingMode: 'user' },
          audio: { echoCancellation: true, noiseSuppression: true },
        });
        const hasCamera = mediaStream.getVideoTracks().some((track) => track.readyState === 'live');
        const hasMicrophone = mediaStream.getAudioTracks().some((track) => track.readyState === 'live');
        if (!hasCamera || !hasMicrophone) {
          throw new Error('未能同时取得摄像头和麦克风权限，请重新检测。');
        }
        if (preview) preview.hidden = false;
        if (video) {
          video.srcObject = mediaStream;
          await video.play().catch(() => {});
        }
        await monitorMicrophone(mediaStream);
        setCard(0, '已开启，可看到实时画面', 'ok');
        setCard(1, '已开启，请测试收音', 'ok');
        setNextEnabled(navigator.onLine);
        if (startBtn) startBtn.textContent = '重新检测设备';
        showMessage(panel, '设备检查通过，可以进入AI语音初面。', true);
      } catch (err) {
        stopStream();
        if (preview) preview.hidden = true;
        setCard(0, '未开启', 'error');
        setCard(1, '未开启', 'error');
        showMessage(panel, permissionMessage(err));
      }
    }

    setNextEnabled(false);
    setCard(2, navigator.onLine ? '网络正常' : '网络异常', navigator.onLine ? 'ok' : 'error');
    startBtn?.addEventListener('click', checkDevices);

    primary?.addEventListener('click', async (event) => {
      event.preventDefault();
      try {
        const hasLiveStream = mediaStream
          && mediaStream.getVideoTracks().some((track) => track.readyState === 'live')
          && mediaStream.getAudioTracks().some((track) => track.readyState === 'live');
        if (!hasLiveStream) {
          throw new Error('请先点击开始设备检测，并允许摄像头和麦克风权限。');
        }
        const res = await post('candidate/device_check.php', {
          token: t,
          camera: true,
          microphone: true,
          network: navigator.onLine ? 'online' : 'offline',
        });
        stopStream();
        location.href = res.data.next || `interview.html?token=${encodeURIComponent(t)}`;
      } catch (err) {
        showMessage(panel, err.message);
      }
    });

    window.addEventListener('pagehide', stopStream);
  }

  function setupInterview() {
    if (page !== 'interview.html') return;
    const t = token();
    const shell = $('.interview-shell');
    const questionBox = $('.question-box');
    const submitBtn = $('#submitInterview') || $('.actions .btn.primary');
    const nextBtn = $('#saveAnswerBtn');
    const startBtn = $('#startRecordBtn');
    const stopBtn = $('#stopRecordBtn');
    const redoBtn = $('#redoRecordBtn');
    const playback = $('#answerPlayback');
    const recordingState = $('#recordingState');
    const videoPreview = $('#interviewVideoPreview');
    const replayBtn = $('.btn.replay');
    const timer = $('#interviewTimer') || $('.timer strong');
    const questionProgress = $('#questionProgress');
    let sessionId = 0;
    let questions = [];
    let index = 0;
    let mediaStream = null;
    let mediaRecorder = null;
    let recordedBlob = null;
    let recordStartedAt = 0;
    let recordedSeconds = 0;
    let recordTimer = null;
    let playbackUrl = '';
    const submitted = new Set();
    let seconds = 15 * 60;
    let voiceLoadAttempts = 0;
    let videoRiskTimer = null;
    let videoRiskBusy = false;
    let faceDetector = null;
    const videoRisk = { supported: 'FaceDetector' in window, samples: 0, face_samples: 0, no_face_samples: 0, multi_face_samples: 0, camera_interruptions: 0 };

    const speak = (text) => {
      if (!window.speechSynthesis || !text) return;
      const voices = window.speechSynthesis.getVoices();
      if (!voices.length && voiceLoadAttempts < 4) {
        voiceLoadAttempts += 1;
        window.setTimeout(() => speak(text), 250);
        return;
      }
      const mandarin = voices.find((voice) => {
        const signature = `${voice.lang} ${voice.name}`.toLowerCase();
        return /zh[-_]?cn|zh[-_]?hans|cmn|mandarin|普通话/.test(signature)
          && !/zh[-_]?hk|yue|canton|粤/.test(signature);
      });
      if (!mandarin) {
        showMessage(shell, '当前设备未找到普通话播报音色，请直接阅读屏幕题目后进行语音回答。');
        return;
      }
      window.speechSynthesis.cancel();
      const utterance = new SpeechSynthesisUtterance(text);
      utterance.lang = 'zh-CN';
      utterance.voice = mandarin;
      utterance.rate = 0.95;
      window.speechSynthesis.speak(utterance);
    };

    if (window.speechSynthesis) {
      window.speechSynthesis.onvoiceschanged = () => {
        voiceLoadAttempts = 0;
      };
    }

    const pushVideoRisk = async () => {
      if (!sessionId) return;
      await post('candidate/update_video_risk.php', { token: t, session_id: sessionId, video_risk: videoRisk }).catch(() => {});
    };

    const sampleVideoRisk = async () => {
      if (videoRiskBusy) return;
      videoRiskBusy = true;
      try {
        const hasVideo = mediaStream && mediaStream.getVideoTracks().some((track) => track.readyState === 'live');
        if (!hasVideo) {
          videoRisk.camera_interruptions += 1;
          await pushVideoRisk();
          return;
        }
        if (!videoRisk.supported || !videoPreview || !videoPreview.videoWidth) {
          await pushVideoRisk();
          return;
        }
        faceDetector = faceDetector || new FaceDetector({ fastMode: true, maxDetectedFaces: 3 });
        const canvas = document.createElement('canvas');
        canvas.width = 160;
        canvas.height = 120;
        const ctx = canvas.getContext('2d');
        ctx.drawImage(videoPreview, 0, 0, canvas.width, canvas.height);
        const faces = await faceDetector.detect(canvas);
        videoRisk.samples += 1;
        if (!faces.length) videoRisk.no_face_samples += 1;
        else videoRisk.face_samples += 1;
        if (faces.length > 1) videoRisk.multi_face_samples += 1;
        await pushVideoRisk();
      } catch (err) {
        videoRisk.supported = false;
        await pushVideoRisk();
      } finally {
        videoRiskBusy = false;
      }
    };

    const startVideoRiskMonitor = () => {
      if (videoRiskTimer) return;
      sampleVideoRisk();
      videoRiskTimer = window.setInterval(sampleVideoRisk, 10000);
    };

    const flushVideoRisk = async () => {
      await sampleVideoRisk();
      await pushVideoRisk();
    };

    const render = () => {
      const q = questions[index];
      if (!q) return;
      const text = q.question_text || q.question || '请结合你的真实经历进行回答。';
      if (questionProgress) questionProgress.textContent = `第 ${index + 1} 题 / ${questions.length} 题`;
      if (questionBox) {
        questionBox.innerHTML = `
          <span>AI提问</span>
          <strong id="questionText">${escapeHtml(text)}</strong>
          <a class="btn replay" href="javascript:void(0)">重听问题</a>
        `;
        $('.btn.replay', questionBox)?.addEventListener('click', () => speak(text));
      }
      resetRecording();
      speak(text);
    };

    const startTimer = () => {
      window.clearInterval(window.__candidateTimer);
      window.__candidateTimer = window.setInterval(() => {
        seconds -= 1;
        if (timer) {
          const mm = String(Math.max(0, Math.floor(seconds / 60))).padStart(2, '0');
          const ss = String(Math.max(0, seconds % 60)).padStart(2, '0');
          timer.textContent = `${mm}:${ss}`;
        }
        if (seconds <= 0) window.clearInterval(window.__candidateTimer);
      }, 1000);
    };

    const updateRecordingState = (text, state = '') => {
      if (!recordingState) return;
      recordingState.textContent = text;
      recordingState.classList.toggle('active', state === 'active');
      recordingState.classList.toggle('done', state === 'done');
    };

    const stopMedia = () => {
      if (mediaStream) {
        mediaStream.getTracks().forEach((track) => track.stop());
        mediaStream = null;
      }
      if (videoPreview) videoPreview.srcObject = null;
    };

    const resetRecording = () => {
      window.clearInterval(recordTimer);
      recordedBlob = null;
      recordedSeconds = 0;
      if (playbackUrl) URL.revokeObjectURL(playbackUrl);
      playbackUrl = '';
      if (playback) {
        playback.hidden = true;
        playback.removeAttribute('src');
      }
      if (startBtn) startBtn.disabled = false;
      if (stopBtn) stopBtn.disabled = true;
      if (redoBtn) redoBtn.disabled = true;
      if (nextBtn) nextBtn.disabled = true;
      if (startBtn) startBtn.textContent = '开始回答';
      if (stopBtn) stopBtn.textContent = '结束回答';
      if (nextBtn) nextBtn.textContent = '提交答案';
      updateRecordingState('尚未开始回答');
    };

    const ensureMedia = async () => {
      if (mediaStream
        && mediaStream.getAudioTracks().some((track) => track.readyState === 'live')
        && mediaStream.getVideoTracks().some((track) => track.readyState === 'live')) return;
      if (!navigator.mediaDevices?.getUserMedia) throw new Error('当前浏览器不支持语音录制，请换用手机系统浏览器');
      mediaStream = await navigator.mediaDevices.getUserMedia({
        video: { facingMode: 'user' },
        audio: { echoCancellation: true, noiseSuppression: true },
      });
      const hasCamera = mediaStream.getVideoTracks().some((track) => track.readyState === 'live');
      const hasMicrophone = mediaStream.getAudioTracks().some((track) => track.readyState === 'live');
      if (!hasCamera || !hasMicrophone) {
        stopMedia();
        throw new Error('请同时开启摄像头和麦克风，摄像头用于面试过程风险检测。');
      }
      if (videoPreview) videoPreview.srcObject = mediaStream;
      startVideoRiskMonitor();
    };

    const preferredMimeType = () => {
      if (!window.MediaRecorder) return '';
      return [
        'audio/webm;codecs=opus',
        'audio/mp4',
        'audio/webm',
        'audio/ogg;codecs=opus',
      ].find((type) => MediaRecorder.isTypeSupported(type)) || '';
    };

    const startRecording = async () => {
      if (!window.MediaRecorder) throw new Error('当前浏览器暂不支持录音，请使用最新版微信或手机浏览器');
      if (window.speechSynthesis) window.speechSynthesis.cancel();
      await ensureMedia();
      const audioStream = new MediaStream(mediaStream.getAudioTracks());
      const mimeType = preferredMimeType();
      mediaRecorder = mimeType ? new MediaRecorder(audioStream, { mimeType }) : new MediaRecorder(audioStream);
      const chunks = [];
      mediaRecorder.ondataavailable = (event) => {
        if (event.data.size > 0) chunks.push(event.data);
      };
      mediaRecorder.onstop = () => {
        recordedSeconds = Math.max(1, Math.round((Date.now() - recordStartedAt) / 1000));
        recordedBlob = new Blob(chunks, { type: mediaRecorder.mimeType || mimeType || 'audio/webm' });
        playbackUrl = URL.createObjectURL(recordedBlob);
        if (playback) {
          playback.src = playbackUrl;
          playback.hidden = false;
        }
        if (redoBtn) redoBtn.disabled = false;
        if (nextBtn) nextBtn.disabled = false;
        updateRecordingState(`录音完成，共 ${recordedSeconds} 秒。请试听后确认提交本题。`, 'done');
      };
      mediaRecorder.start(500);
      recordStartedAt = Date.now();
      if (startBtn) startBtn.disabled = true;
      if (startBtn) startBtn.textContent = '回答中';
      if (stopBtn) stopBtn.disabled = false;
      if (redoBtn) redoBtn.disabled = true;
      if (nextBtn) nextBtn.disabled = true;
      updateRecordingState('正在录音 00:00，请完整回答当前问题。', 'active');
      recordTimer = window.setInterval(() => {
        const elapsed = Math.round((Date.now() - recordStartedAt) / 1000);
        const mm = String(Math.floor(elapsed / 60)).padStart(2, '0');
        const ss = String(elapsed % 60).padStart(2, '0');
        updateRecordingState(`正在录音 ${mm}:${ss}，请完整回答当前问题。`, 'active');
      }, 1000);
    };

    const stopRecording = () => {
      window.clearInterval(recordTimer);
      if (mediaRecorder && mediaRecorder.state === 'recording') {
        mediaRecorder.stop();
      }
      if (stopBtn) stopBtn.disabled = true;
      if (startBtn) startBtn.disabled = true;
      if (stopBtn) stopBtn.textContent = '正在整理录音';
    };

    const saveCurrent = async () => {
      const q = questions[index];
      if (!q || !recordedBlob) throw new Error('请先录制本题语音回答');
      if (window.speechSynthesis) window.speechSynthesis.cancel();
      const form = new FormData();
      form.append('token', t);
      form.append('session_id', String(sessionId));
      form.append('question_id', q.id || '');
      form.append('question_text', q.question_text || q.question);
      form.append('question_type', q.question_type || q.type || '');
      form.append('audio_seconds', String(recordedSeconds));
      form.append('sort_order', String(index + 1));
      const ext = recordedBlob.type.includes('mp4') ? 'm4a' : recordedBlob.type.includes('ogg') ? 'ogg' : 'webm';
      form.append('audio_file', recordedBlob, `answer-${index + 1}.${ext}`);
      await postForm('candidate/upload_answer_audio.php', form);
      submitted.add(index);
    };

    startBtn?.addEventListener('click', async () => {
      try {
        await startRecording();
      } catch (err) {
        showMessage(shell, err.message);
      }
    });

    stopBtn?.addEventListener('click', stopRecording);

    redoBtn?.addEventListener('click', async () => {
      resetRecording();
      try {
        await startRecording();
      } catch (err) {
        showMessage(shell, err.message);
      }
    });

    nextBtn?.addEventListener('click', async () => {
      try {
        nextBtn.disabled = true;
        nextBtn.textContent = '提交中...';
        updateRecordingState('正在安全保存本题语音，请稍候...');
        await saveCurrent();
        if (index < questions.length - 1) {
          index += 1;
          render();
          showMessage(shell, '上一题语音已保存，请继续回答下一题。', true);
        } else {
          submitBtn.disabled = false;
          nextBtn.textContent = '已提交';
          updateRecordingState('全部语音回答已保存，可以提交面试。', 'done');
          showMessage(shell, '全部语音回答已安全保存。', true);
        }
      } catch (err) {
        nextBtn.disabled = false;
        nextBtn.textContent = '提交答案';
        showMessage(shell, err.message);
      }
    });

    submitBtn?.addEventListener('click', async () => {
      try {
        submitBtn.disabled = true;
        showMessage(shell, '正在提交本次语音面试，请稍候...', true);
        await flushVideoRisk();
        const res = await post('candidate/submit_audio_interview.php', { token: t, session_id: sessionId });
        location.href = res.data.next || `complete.html?token=${encodeURIComponent(t)}&session_id=${sessionId}`;
      } catch (err) {
        submitBtn.disabled = false;
        showMessage(shell, err.message);
      }
    });

    get(`candidate/interview_start.php?token=${encodeURIComponent(t)}`).then(({ data }) => {
      sessionId = data.session_id;
      questions = data.questions || [];
      if (!questions.length) throw new Error('暂无可用面试问题，请联系HR');
      startTimer();
      render();
    }).catch((err) => showMessage(shell, err.message));

    replayBtn?.addEventListener('click', () => {
      const q = questions[index];
      if (q) speak(q.question_text || q.question);
    });

    window.addEventListener('pagehide', stopMedia);
  }

  function setupInterviewV2() {
    if (page !== 'interview.html') return;
    const t = token();
    const shell = $('.interview-shell');
    const questionBox = $('.question-box');
    const submitBtn = $('#submitInterview');
    const mainBtn = $('#answerMainBtn') || $('#startRecordBtn');
    const redoBtn = $('#redoRecordBtn');
    const playback = $('#answerPlayback');
    const recordingState = $('#recordingState');
    const videoPreview = $('#interviewVideoPreview');
    const timer = $('#interviewTimer') || $('.timer strong');
    const questionProgress = $('#questionProgress');

    let sessionId = 0;
    let questions = [];
    let index = 0;
    let mediaStream = null;
    let mediaRecorder = null;
    let recordStartedAt = 0;
    let recordTimer = null;
    let playbackUrl = '';
    let isRecording = false;
    let isQuestionReady = false;
    let currentStopResolve = null;
    let currentChunks = [];
    let seconds = 15 * 60;
    let voiceLoadAttempts = 0;
    let videoRiskTimer = null;
    let videoRiskBusy = false;
    let faceDetector = null;
    const videoRisk = { supported: 'FaceDetector' in window, samples: 0, face_samples: 0, no_face_samples: 0, multi_face_samples: 0, camera_interruptions: 0 };
    const savedAnswers = new Set();
    const pendingSaves = new Map();
    const failedSaves = new Map();

    const setState = (text, state = '') => {
      if (!recordingState) return;
      recordingState.textContent = text;
      recordingState.classList.toggle('active', state === 'active');
      recordingState.classList.toggle('done', state === 'done');
    };

    const setMain = (text, disabled = false) => {
      if (!mainBtn) return;
      mainBtn.textContent = text;
      mainBtn.disabled = disabled;
    };

    const pushVideoRisk = async () => {
      if (!sessionId) return;
      await post('candidate/update_video_risk.php', { token: t, session_id: sessionId, video_risk: videoRisk }).catch(() => {});
    };

    const sampleVideoRisk = async () => {
      if (videoRiskBusy) return;
      videoRiskBusy = true;
      try {
        const hasVideo = mediaStream && mediaStream.getVideoTracks().some((track) => track.readyState === 'live');
        if (!hasVideo) {
          videoRisk.camera_interruptions += 1;
          await pushVideoRisk();
          return;
        }
        if (!videoRisk.supported || !videoPreview || !videoPreview.videoWidth) {
          await pushVideoRisk();
          return;
        }
        faceDetector = faceDetector || new FaceDetector({ fastMode: true, maxDetectedFaces: 3 });
        const canvas = document.createElement('canvas');
        canvas.width = 160;
        canvas.height = 120;
        const ctx = canvas.getContext('2d');
        ctx.drawImage(videoPreview, 0, 0, canvas.width, canvas.height);
        const faces = await faceDetector.detect(canvas);
        videoRisk.samples += 1;
        if (!faces.length) videoRisk.no_face_samples += 1;
        else videoRisk.face_samples += 1;
        if (faces.length > 1) videoRisk.multi_face_samples += 1;
        await pushVideoRisk();
      } catch (err) {
        videoRisk.supported = false;
        await pushVideoRisk();
      } finally {
        videoRiskBusy = false;
      }
    };

    const startVideoRiskMonitor = () => {
      if (videoRiskTimer) return;
      sampleVideoRisk();
      videoRiskTimer = window.setInterval(sampleVideoRisk, 10000);
    };

    const flushVideoRisk = async () => {
      await sampleVideoRisk();
      await pushVideoRisk();
    };

    const currentQuestion = () => questions[index] || null;

    const stopMedia = () => {
      if (mediaRecorder && mediaRecorder.state === 'recording') {
        try { mediaRecorder.stop(); } catch (err) {}
      }
      if (mediaStream) {
        mediaStream.getTracks().forEach((track) => track.stop());
        mediaStream = null;
      }
      if (videoPreview) videoPreview.srcObject = null;
    };

    const resetRecording = () => {
      window.clearInterval(recordTimer);
      recordTimer = null;
      isRecording = false;
      currentChunks = [];
      currentStopResolve = null;
      if (playbackUrl) URL.revokeObjectURL(playbackUrl);
      playbackUrl = '';
      if (playback) {
        playback.hidden = true;
        playback.removeAttribute('src');
      }
      if (redoBtn) redoBtn.disabled = true;
      setState(isQuestionReady ? '请点击“开始回答”，用语音回答本题。' : '请先听完问题');
      setMain(isQuestionReady ? '开始回答' : '请听完问题', !isQuestionReady);
    };

    const markQuestionReady = () => {
      isQuestionReady = true;
      setMain('开始回答', false);
      setState('请点击“开始回答”，用语音回答本题。');
    };

    const speakQuestion = (text) => {
      isQuestionReady = false;
      setMain('请听完问题', true);
      setState('AI正在读题，请听完后再回答。');
      if (!window.speechSynthesis || !text) {
        markQuestionReady();
        return;
      }
      const voices = window.speechSynthesis.getVoices();
      if (!voices.length && voiceLoadAttempts < 4) {
        voiceLoadAttempts += 1;
        window.setTimeout(() => speakQuestion(text), 250);
        return;
      }
      const mandarin = voices.find((voice) => {
        const signature = `${voice.lang} ${voice.name}`.toLowerCase();
        return /zh[-_]?cn|zh[-_]?hans|cmn|mandarin|putonghua/.test(signature)
          && !/zh[-_]?hk|zh[-_]?tw|yue|canton/.test(signature);
      }) || voices.find((voice) => /zh/i.test(`${voice.lang} ${voice.name}`));
      window.speechSynthesis.cancel();
      const utterance = new SpeechSynthesisUtterance(text);
      utterance.lang = 'zh-CN';
      if (mandarin) utterance.voice = mandarin;
      utterance.rate = 0.95;
      utterance.onend = markQuestionReady;
      utterance.onerror = markQuestionReady;
      window.speechSynthesis.speak(utterance);
      window.setTimeout(() => {
        if (!isQuestionReady && !window.speechSynthesis.speaking) markQuestionReady();
      }, 1200);
    };

    if (window.speechSynthesis) {
      window.speechSynthesis.onvoiceschanged = () => {
        voiceLoadAttempts = 0;
      };
    }

    const render = () => {
      const q = currentQuestion();
      if (!q) return;
      const text = q.question_text || q.question || '请结合你的真实经历进行回答。';
      if (questionProgress) questionProgress.textContent = `第 ${index + 1} 题 / 共 ${questions.length} 题`;
      if (questionBox) {
        questionBox.innerHTML = `
          <span>AI提问</span>
          <strong id="questionText">${escapeHtml(text)}</strong>
          <a class="btn replay" href="javascript:void(0)">重听问题</a>
        `;
        $('.btn.replay', questionBox)?.addEventListener('click', () => speakQuestion(text));
      }
      resetRecording();
      speakQuestion(text);
    };

    const startTimer = () => {
      window.clearInterval(window.__candidateTimer);
      window.__candidateTimer = window.setInterval(() => {
        seconds -= 1;
        if (timer) {
          const mm = String(Math.max(0, Math.floor(seconds / 60))).padStart(2, '0');
          const ss = String(Math.max(0, seconds % 60)).padStart(2, '0');
          timer.textContent = `${mm}:${ss}`;
        }
        if (seconds <= 0) window.clearInterval(window.__candidateTimer);
      }, 1000);
    };

    const ensureMedia = async () => {
      if (mediaStream
        && mediaStream.getAudioTracks().some((track) => track.readyState === 'live')
        && mediaStream.getVideoTracks().some((track) => track.readyState === 'live')) return;
      if (!navigator.mediaDevices?.getUserMedia) throw new Error('当前浏览器不支持语音录制，请使用微信或手机系统浏览器打开。');
      mediaStream = await navigator.mediaDevices.getUserMedia({
        video: { facingMode: 'user' },
        audio: { echoCancellation: true, noiseSuppression: true },
      });
      const hasCamera = mediaStream.getVideoTracks().some((track) => track.readyState === 'live');
      const hasMicrophone = mediaStream.getAudioTracks().some((track) => track.readyState === 'live');
      if (!hasCamera || !hasMicrophone) {
        stopMedia();
        throw new Error('请同时开启摄像头和麦克风，摄像头用于面试过程风险检测。');
      }
      if (videoPreview) videoPreview.srcObject = mediaStream;
      startVideoRiskMonitor();
    };

    const preferredMimeType = () => {
      if (!window.MediaRecorder) return '';
      return [
        'audio/webm;codecs=opus',
        'audio/mp4',
        'audio/webm',
        'audio/ogg;codecs=opus',
      ].find((type) => MediaRecorder.isTypeSupported(type)) || '';
    };

    const startRecording = async () => {
      if (!isQuestionReady) return;
      if (!window.MediaRecorder) throw new Error('当前浏览器暂不支持录音，请使用最新版微信或手机浏览器。');
      if (window.speechSynthesis) window.speechSynthesis.cancel();
      await ensureMedia();
      const audioStream = new MediaStream(mediaStream.getAudioTracks());
      const mimeType = preferredMimeType();
      mediaRecorder = mimeType ? new MediaRecorder(audioStream, { mimeType }) : new MediaRecorder(audioStream);
      currentChunks = [];
      mediaRecorder.ondataavailable = (event) => {
        if (event.data.size > 0) currentChunks.push(event.data);
      };
      mediaRecorder.onstop = () => {
        const recordedSeconds = Math.max(1, Math.round((Date.now() - recordStartedAt) / 1000));
        const recordedBlob = new Blob(currentChunks, { type: mediaRecorder.mimeType || mimeType || 'audio/webm' });
        if (currentStopResolve) {
          currentStopResolve({ blob: recordedBlob, seconds: recordedSeconds });
          currentStopResolve = null;
        }
      };
      mediaRecorder.start(500);
      isRecording = true;
      recordStartedAt = Date.now();
      if (redoBtn) redoBtn.disabled = false;
      setMain('说完了，下一题', false);
      setState('正在录音 00:00。说错了可点“重录本题”。', 'active');
      recordTimer = window.setInterval(() => {
        const elapsed = Math.round((Date.now() - recordStartedAt) / 1000);
        const mm = String(Math.floor(elapsed / 60)).padStart(2, '0');
        const ss = String(elapsed % 60).padStart(2, '0');
        setState(`正在录音 ${mm}:${ss}。说完后点“说完了，下一题”。`, 'active');
      }, 1000);
    };

    const stopRecording = () => new Promise((resolve) => {
      window.clearInterval(recordTimer);
      recordTimer = null;
      if (!mediaRecorder || mediaRecorder.state !== 'recording') {
        resolve(null);
        return;
      }
      currentStopResolve = resolve;
      mediaRecorder.stop();
      isRecording = false;
    });

    const uploadAnswer = async (questionIndex, q, recorded) => {
      const form = new FormData();
      form.append('token', t);
      form.append('session_id', String(sessionId));
      form.append('question_id', q.id || '');
      form.append('question_text', q.question_text || q.question);
      form.append('question_type', q.question_type || q.type || '');
      form.append('audio_seconds', String(recorded.seconds));
      form.append('sort_order', String(questionIndex + 1));
      const ext = recorded.blob.type.includes('mp4') ? 'm4a' : recorded.blob.type.includes('ogg') ? 'ogg' : 'webm';
      form.append('audio_file', recorded.blob, `answer-${questionIndex + 1}.${ext}`);
      await postForm('candidate/upload_answer_audio.php', form);
      savedAnswers.add(questionIndex);
      failedSaves.delete(questionIndex);
    };

    const queueSaveAndMoveNext = (questionIndex, q, recorded) => {
      const key = String(questionIndex);
      setState('本题答案保存中，已进入下一步。');
      const task = uploadAnswer(questionIndex, q, recorded)
        .then(() => setState(questionIndex === index ? '本题答案已保存。' : '上一题答案已保存。', 'done'))
        .catch((err) => {
          failedSaves.set(questionIndex, { q, recorded, err });
          showMessage(shell, `第 ${questionIndex + 1} 题保存失败，请稍后重试或重新回答。`);
        })
        .finally(() => pendingSaves.delete(key));
      pendingSaves.set(key, task);

      if (questionIndex < questions.length - 1) {
        index = questionIndex + 1;
        render();
      } else {
        setMain('已完成全部题目', true);
        if (redoBtn) redoBtn.disabled = true;
        if (submitBtn) submitBtn.disabled = false;
        setState('全部题目已回答，正在确认答案保存状态。', 'done');
        showMessage(shell, '全部题目已回答，可以提交面试。', true);
      }
    };

    const retryFailedSaves = async () => {
      const retries = Array.from(failedSaves.entries()).map(([questionIndex, item]) => (
        uploadAnswer(Number(questionIndex), item.q, item.recorded).catch((err) => {
          failedSaves.set(Number(questionIndex), { ...item, err });
        })
      ));
      if (retries.length) await Promise.all(retries);
    };

    const redoCurrentQuestion = async () => {
      if (!isQuestionReady) return;
      try {
        if (isRecording) await stopRecording();
        resetRecording();
        await startRecording();
      } catch (err) {
        showMessage(shell, err.message);
      }
    };

    mainBtn?.addEventListener('click', async () => {
      try {
        if (!isRecording) {
          await startRecording();
          return;
        }
        const q = currentQuestion();
        const questionIndex = index;
        setMain('正在保存...', true);
        if (redoBtn) redoBtn.disabled = true;
        const recorded = await stopRecording();
        if (!recorded || !recorded.blob || recorded.blob.size < 1) {
          setMain('开始回答', false);
          if (redoBtn) redoBtn.disabled = true;
          throw new Error('本题没有录到有效语音，请重新回答。');
        }
        playbackUrl = URL.createObjectURL(recorded.blob);
        if (playback) {
          playback.src = playbackUrl;
          playback.hidden = false;
        }
        queueSaveAndMoveNext(questionIndex, q, recorded);
      } catch (err) {
        showMessage(shell, err.message);
      }
    });

    redoBtn?.addEventListener('click', redoCurrentQuestion);

    submitBtn?.addEventListener('click', async () => {
      try {
        if (isRecording) throw new Error('当前题目还在录音，请先点“说完了，下一题”。');
        submitBtn.disabled = true;
        showMessage(shell, '正在检查语音答案保存状态，请稍候...', true);
        await Promise.all(Array.from(pendingSaves.values()));
        if (failedSaves.size > 0) {
          showMessage(shell, '有答案刚才保存失败，系统正在自动重试一次...', true);
          await retryFailedSaves();
        }
        if (failedSaves.size > 0) {
          submitBtn.disabled = false;
          throw new Error('仍有语音答案保存失败，请检查网络后重新提交。');
        }
        if (savedAnswers.size < questions.length) {
          submitBtn.disabled = false;
          throw new Error('还有题目未完成语音回答，请先完成全部题目。');
        }
        showMessage(shell, '正在提交本次语音面试，请稍候...', true);
        await flushVideoRisk();
        const res = await post('candidate/submit_audio_interview.php', { token: t, session_id: sessionId });
        location.href = res.data.next || `complete.html?token=${encodeURIComponent(t)}&session_id=${sessionId}`;
      } catch (err) {
        submitBtn.disabled = false;
        showMessage(shell, err.message);
      }
    });

    get(`candidate/interview_start.php?token=${encodeURIComponent(t)}`).then(({ data }) => {
      sessionId = data.session_id;
      questions = data.questions || [];
      if (!questions.length) throw new Error('暂无可用面试问题，请联系HR。');
      (data.answered_sort_orders || []).forEach((order) => {
        const answeredIndex = Number(order) - 1;
        if (answeredIndex >= 0) savedAnswers.add(answeredIndex);
      });
      const firstUnanswered = questions.findIndex((_, questionIndex) => !savedAnswers.has(questionIndex));
      if (firstUnanswered >= 0) {
        index = firstUnanswered;
        if (index > 0) showMessage(shell, `已恢复进度，从第 ${index + 1} 题继续。`, true);
      } else {
        index = Math.max(0, questions.length - 1);
        if (submitBtn) submitBtn.disabled = false;
        showMessage(shell, '已恢复进度，全部题目已回答，可以直接提交面试。', true);
      }
      startTimer();
      render();
    }).catch((err) => showMessage(shell, err.message));

    window.addEventListener('pagehide', stopMedia);
  }

  function setupComplete() {
    if (page !== 'complete.html') return;
    const t = token();
    const sessionId = params.get('session_id') || '';
    const list = $('#transcriptList') || $('.transcript');
    const imageBtn = $('#saveTranscriptImage');
    const downloadList = $('#audioDownloads');
    if (!list || !t) return;

    let transcriptItems = [];

    const wrapCanvasText = (ctx, text, x, y, maxWidth, lineHeight) => {
      const chars = String(text || '').split('');
      let line = '';
      chars.forEach((ch) => {
        const testLine = line + ch;
        if (ctx.measureText(testLine).width > maxWidth && line) {
          ctx.fillText(line, x, y);
          line = ch;
          y += lineHeight;
        } else {
          line = testLine;
        }
      });
      if (line) {
        ctx.fillText(line, x, y);
        y += lineHeight;
      }
      return y;
    };

    const downloadTranscriptImage = () => {
      if (!transcriptItems.length) return;
      const width = 900;
      const padding = 44;
      const lineHeight = 30;
      const maxWidth = width - padding * 2;
      const canvas = document.createElement('canvas');
      const ctx = canvas.getContext('2d');
      ctx.font = '22px Microsoft YaHei, sans-serif';
      let height = 120;
      transcriptItems.forEach((item) => {
        height += 54;
        height += Math.ceil(ctx.measureText(item.question).width / maxWidth) * lineHeight;
        height += Math.max(1, Math.ceil(ctx.measureText(item.answer).width / maxWidth)) * lineHeight;
        height += 34;
      });
      canvas.width = width;
      canvas.height = Math.max(420, height);

      ctx.fillStyle = '#f5fafb';
      ctx.fillRect(0, 0, canvas.width, canvas.height);
      ctx.fillStyle = '#174d7c';
      ctx.font = 'bold 34px Microsoft YaHei, sans-serif';
      ctx.fillText('AI全量初面系统｜面试转写记录', padding, 64);
      ctx.fillStyle = '#5b6b7a';
      ctx.font = '18px Microsoft YaHei, sans-serif';
      ctx.fillText('仅供候选人本人复盘使用，不包含岗位匹配度、推进建议或HR复核结果。', padding, 98);

      let y = 142;
      transcriptItems.forEach((item, idx) => {
        ctx.fillStyle = '#ffffff';
        ctx.fillRect(padding - 16, y - 28, width - padding * 2 + 32, 36);
        ctx.fillStyle = '#174d7c';
        ctx.font = 'bold 22px Microsoft YaHei, sans-serif';
        ctx.fillText(`第 ${idx + 1} 题`, padding, y);
        y += 38;

        ctx.fillStyle = '#17212b';
        ctx.font = 'bold 22px Microsoft YaHei, sans-serif';
        y = wrapCanvasText(ctx, `AI：${item.question}`, padding, y, maxWidth, lineHeight);
        y += 10;

        ctx.fillStyle = '#2d3a45';
        ctx.font = '22px Microsoft YaHei, sans-serif';
        y = wrapCanvasText(ctx, `候选人：${item.answer || '语音转写处理中'}`, padding, y, maxWidth, lineHeight);
        y += 30;
      });

      const a = document.createElement('a');
      a.href = canvas.toDataURL('image/png');
      a.download = `AI初面转写记录-${sessionId || Date.now()}.png`;
      a.click();
    };

    imageBtn?.addEventListener('click', downloadTranscriptImage);

    get(`candidate/interview_result.php?token=${encodeURIComponent(t)}&session_id=${encodeURIComponent(sessionId)}`).then(({ data }) => {
      const answers = data.answers || [];
      const recordings = data.recordings || [];
      const isAudio = data.interview_type === 'audio';
      let html = '';
      transcriptItems = [];

      if (isAudio && recordings.length > 0) {
        // 语音面试：显示题目 + 转写内容
        html = recordings.map((item) => {
          const transcriptText = item.transcript_text || '';
          const statusLabel = item.transcript_status === 'completed'
            ? '已转写'
            : item.transcript_status === 'failed'
              ? '转写失败'
              : '转写处理中';
          const answerContent = transcriptText
            ? `<p>${escapeHtml(transcriptText)}</p>`
            : `<p class="soft-note">语音回答（${item.audio_seconds || 0}秒）${statusLabel}</p>`;
          transcriptItems.push({
            question: item.question_text || '面试问题',
            answer: transcriptText || `语音回答（${item.audio_seconds || 0}秒），${statusLabel}`,
          });
          return `
            <div class="dialog"><span>AI</span><strong>${escapeHtml(item.question_text || '面试问题')}</strong></div>
            <div class="dialog"><span>候选人</span>${answerContent}</div>
          `;
        }).join('');
        if (downloadList) {
          downloadList.innerHTML = recordings.map((item, idx) => item.download_url ? `
            <a class="download-item" href="${escapeHtml(item.download_url)}" target="_blank" rel="noreferrer" download>
              下载第 ${idx + 1} 题录音
              <small>${item.audio_seconds || 0}秒，有效期约24小时</small>
            </a>
          ` : `
            <div class="download-item disabled">第 ${idx + 1} 题录音暂不可下载<small>请稍后刷新页面</small></div>
          `).join('');
        }
      } else if (answers.length > 0) {
        // 文字面试：显示问答
        html = answers.map((item) => `
          <div class="dialog"><span>AI</span><strong>${escapeHtml(item.question_text)}</strong></div>
          <div class="dialog"><span>候选人</span><p>${escapeHtml(item.answer_text)}</p></div>
        `).join('');
        transcriptItems = answers.map((item) => ({
          question: item.question_text,
          answer: item.answer_text,
        }));
        if (downloadList) downloadList.innerHTML = '<div class="soft-note">本次面试暂无语音录音文件。</div>';
      }

      list.innerHTML = html || '<div class="soft-note">暂无面试复盘记录。</div>';
      if (imageBtn) imageBtn.disabled = transcriptItems.length === 0;
      const audio = $('#audioMessage');
      if (audio) audio.textContent = data.audio_message || '面试记录已保存。';
    }).catch((err) => showMessage(list, err.message));
  }

  enforceDailyCandidateLogin();
  keepTokenOnLinks();
  setupCandidateSessionActions();
  setupInvite();
  setupLogin();
  setupAuth();
  setupResume();
  setupJobConfirm();
  setupDeviceCheck();
  setupInterviewV2();
  // 完成页由 materials-flow.js 接管，避免旧的逐题录音下载逻辑重复渲染。
})();
