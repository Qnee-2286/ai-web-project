(() => {
  const API = '../api';
  const page = location.pathname.split('/').pop() || 'dashboard.html';

  function $(selector, root = document) {
    return root.querySelector(selector);
  }

  async function post(path, payload) {
    const response = await fetch(`${API}/${path}`, {
      method: 'POST',
      headers: {'Content-Type': 'application/json'},
      credentials: 'include',
      body: JSON.stringify(payload || {})
    });
    const data = await response.json().catch(() => ({ok: false, message: '接口返回格式错误'}));
    if (data?.data?.need_login) {
      location.href = './login.html';
      return new Promise(() => {});
    }
    if (!response.ok || !data.ok) {
      throw new Error(data.message || '请求失败');
    }
    return data;
  }

  async function get(path) {
    const response = await fetch(`${API}/${path}`, {credentials: 'include'});
    const data = await response.json().catch(() => ({ok: false, message: '接口返回格式错误'}));
    if (data?.data?.need_login) {
      location.href = './login.html';
      return new Promise(() => {});
    }
    if (!response.ok || !data.ok) {
      throw new Error(data.message || '请求失败');
    }
    return data;
  }

  function showMessage(text, ok = false) {
    const box = $('#flowMessage') || $('.form-message');
    if (!box) {
      if (text) alert(text);
      return;
    }
    box.hidden = false;
    box.textContent = text;
    box.classList.toggle('ok', ok);
  }

  async function copyText(text) {
    if (!text || text === '-') return;
    if (navigator.clipboard?.writeText) {
      await navigator.clipboard.writeText(text);
      return;
    }
    const temp = document.createElement('textarea');
    temp.value = text;
    document.body.appendChild(temp);
    temp.select();
    document.execCommand('copy');
    temp.remove();
  }

  function setupCopyButtons() {
    document.querySelectorAll('[data-copy-source]').forEach((button) => {
      button.addEventListener('click', async () => {
        const source = document.getElementById(button.dataset.copySource);
        const text = source ? source.textContent.trim() : '';
        try {
          await copyText(text);
          const old = button.textContent;
          button.textContent = '已复制';
          setTimeout(() => { button.textContent = old || '复制'; }, 1200);
        } catch (_) {
          alert('复制失败，请手动复制。');
        }
      });
    });
  }

  function setupDailyQuote() {
    const dailyQuote = $('#dailyQuote');
    if (!dailyQuote) return;
    const quotes = [
      { text: '先看见真实的人，再做可靠的判断。', source: '泓泽数字' },
      { text: '管理的核心，是让平凡的人做出不平凡的事。', source: '彼得·德鲁克' },
      { text: '己所不欲，勿施于人。', source: '论语' },
      { text: '人不是工具，人是目的。', source: '康德思想转述' },
      { text: '组织真正要处理的，从来都是人的理解、协作与信任。', source: '泓泽数字' },
      { text: '制度让事情有章可循，尊重让人愿意同行。', source: '泓泽数字' }
    ];
    const start = new Date('2026-04-29T00:00:00+08:00');
    const day = Math.floor((new Date() - start) / 86400000);
    const item = quotes[((day % quotes.length) + quotes.length) % quotes.length];
    const text = dailyQuote.querySelector('p');
    const source = dailyQuote.querySelector('.quote-source');
    if (text) text.textContent = item.text;
    if (source) source.textContent = item.source;
  }

  function setActivationCard(id, done, doneText, pendingText) {
    const card = document.getElementById(id);
    if (!card) return;
    card.classList.toggle('done', !!done);
    const strong = card.querySelector('strong');
    if (strong) strong.textContent = done ? doneText : pendingText;
  }

  function dashboardJobLocation(job) {
    return String(job?.work_location || '').trim() || '工作地点待补充';
  }

  async function setupDashboardGuide() {
    if (page !== 'dashboard.html') return;
    try {
      const me = await get('auth/me.php');
      const data = me.data || {};
      const emailDone = !!data.email_verified;
      const realDone = data.realname_status === 'verified';
      const companyDone = data.company_verification_status === 'verified';

      setActivationCard('phoneStep', true, '已完成', '待完成');
      setActivationCard('emailStep', emailDone, '已绑定', '待完成');
      setActivationCard('realnameStep', realDone, '已实名', '待完成');
      setActivationCard('companyStep', companyDone, '已认证', '可稍后');

      const nameEl = $('#dashboardUserName');
      const metaEl = $('#dashboardUserMeta');
      const avatarEl = $('#dashboardAvatar');
      if (nameEl) nameEl.textContent = data.name || '当前HR';
      if (metaEl) metaEl.textContent = `${data.phone || ''}${data.email ? ' · ' + data.email : ''}`;
      if (avatarEl) avatarEl.textContent = (data.name || 'HR').slice(0, 2).toUpperCase();

      const cta = $('#dashboardPrimaryCta');
      const title = $('#dashboardStartTitle');
      if (!emailDone) {
        if (cta) { cta.textContent = '去绑定邮箱'; cta.href = './bind-email.html'; }
        if (title) title.textContent = '你还差2步，就可以发出第一场AI初面';
      } else if (!realDone) {
        if (cta) { cta.textContent = '去完成HR实名'; cta.href = './realname.html'; }
        if (title) title.textContent = '你还差1步，就可以发出第一场AI初面';
      } else {
        if (cta) { cta.textContent = '创建第一场AI初面'; cta.href = './create-interview-flow.html?v=20260615cachefix'; }
        if (title) title.textContent = '账号已启用，可以创建第一场AI初面';
      }
    } catch (_) {}

    try {
      const summary = await get('hr/dashboard_summary.php');
      const jobs = summary.data?.recent_jobs || [];
      const panel = $('#dashboardRecentPanel');
      const tbody = $('#dashboardRecentJobs');
      if (!panel || !tbody || !jobs.length) return;
      panel.hidden = false;
      tbody.innerHTML = '';
      jobs.forEach((job) => {
        const needsReview = Number(job.review_pending_count || 0);
        const tr = document.createElement('tr');
        tr.innerHTML = `
          <td><strong>${job.job_title || '-'}</strong><br><small>${dashboardJobLocation(job)}</small></td>
          <td>${job.candidate_count || 0}人</td>
          <td><span class="tag ${needsReview ? 'orange' : 'blue'}">${needsReview ? '待复核' : '进行中'}</span></td>
          <td><a class="primary-link" href="${needsReview ? './report-review-list.html' : './candidate-list.html'}">${needsReview ? '处理报告' : '查看候选人'}</a></td>`;
        tbody.appendChild(tr);
      });
    } catch (_) {}
  }

  function readForm(form) {
    return Object.fromEntries(new FormData(form).entries());
  }

  function validateJob(payload) {
    const required = [
      ['company_name', '公司名称'],
      ['job_title', '岗位名称'],
      ['responsibilities', '岗位职责'],
      ['requirements', '任职要求']
    ];
    const missing = required.find(([key]) => !String(payload[key] || '').trim());
    if (missing) throw new Error(`请填写${missing[1]}`);
  }

  function questionItem(question, type = '基础必问题', required = true) {
    return {
      question: question,
      type: type,
      difficulty: type === '基础必问题' ? '基础' : '中等',
      purpose: type === '基础必问题' ? '确认基础情况和求职意向' : '验证岗位匹配度',
      is_required: required
    };
  }

  const defaultBasicQuestions = [
    questionItem('请做一个1-2分钟的自我介绍，重点说你和这个岗位相关的经历。'),
    questionItem('你为什么离开上一份工作，或者为什么正在考虑新的机会？'),
    questionItem('你为什么考虑这个岗位？你理解这个岗位最重要的工作是什么？'),
    questionItem('你的期望薪资和最快到岗时间是怎样的？')
  ];

  function renderEditableQuestions(container, questions, onChange) {
    if (!container) return;
    container.innerHTML = '';
    if (!questions.length) {
      container.innerHTML = '<article class="empty-question">暂无问题，请添加或使用AI生成。</article>';
      return;
    }
    questions.forEach((item, index) => {
      const row = document.createElement('article');
      row.className = 'editable-question';
      row.innerHTML = `
        <div class="question-meta">
          <span>${item.is_required ? '必问' : '备选'}</span>
          <small>${item.type || '面试问题'} · ${item.difficulty || '中等'}</small>
        </div>
        <textarea aria-label="面试问题">${item.question || item.question_text || ''}</textarea>
        <div class="question-tools">
          <button type="button" data-action="required">${item.is_required ? '取消必问' : '设为必问'}</button>
          <button type="button" data-action="delete">删除</button>
        </div>`;
      const textarea = row.querySelector('textarea');
      textarea.addEventListener('input', () => {
        item.question = textarea.value.trim();
        onChange?.();
      });
      row.querySelector('[data-action="required"]').addEventListener('click', () => {
        item.is_required = !item.is_required;
        renderEditableQuestions(container, questions, onChange);
        onChange?.();
      });
      row.querySelector('[data-action="delete"]').addEventListener('click', () => {
        questions.splice(index, 1);
        renderEditableQuestions(container, questions, onChange);
        onChange?.();
      });
      container.appendChild(row);
    });
  }

  function formatFlowSalary(payload) {
    const unit = payload.salary_unit || 'K/月';
    if (unit === '面议') return '面议';
    const min = String(payload.salary_min_k || '').trim();
    const max = String(payload.salary_max_k || '').trim();
    if (!min || !max) return '待填写';
    return unit === '元/天' ? (min + '-' + max + '元/天') : (min + '-' + max + 'K/月');
  }

  function textOrPending(value) {
    const clean = String(value || '').trim();
    return clean || '待填写';
  }

  function updateCandidatePreview(payload) {
    const map = {
      flowPreviewJobTitle: textOrPending(payload.job_title),
      flowPreviewCompanyName: textOrPending(payload.company_name),
      flowPreviewSalary: formatFlowSalary(payload),
      flowPreviewBenefits: textOrPending(payload.benefits),
      flowPreviewCompanyIntro: textOrPending(payload.company_intro),
      flowPreviewResponsibilities: textOrPending(payload.responsibilities),
      flowPreviewRequirements: textOrPending(payload.requirements)
    };
    Object.entries(map).forEach(([id, value]) => {
      const el = document.getElementById(id);
      if (el) el.textContent = value;
    });
  }

  function setupCreateInterviewWizard() {
    if (page !== 'create-interview-flow.html') return;
    const wizard = $('#createInterviewWizard');
    const form = $('#interviewJobForm');
    if (!wizard || !form) return;

    const stepButtons = Array.from(wizard.querySelectorAll('[data-step]'));
    const panels = Array.from(wizard.querySelectorAll('[data-panel]'));
    const prev = $('#wizardPrev');
    const next = $('#wizardNext');
    const generateBtn = $('#flowGenerateQuestions');
    const addBasicBtn = $('#flowAddBasicQuestion');
    const openBank = $('#flowOpenQuestionBank');
    const createInviteBtn = $('#flowCreateInvite');
    const inviteLink = $('#flowInviteLink');
    const inviteText = $('#flowInviteText');
    const basicList = $('#flowBasicQuestions');
    const jobList = $('#flowJobQuestions');
    const params = new URLSearchParams(location.search);

    let current = Math.min(4, Math.max(1, Number(params.get('step') || 1)));
    let jobId = params.get('job_id') || '';
    let savedPayload = null;
    let basicQuestions = defaultBasicQuestions.map((item) => ({...item}));
    let jobQuestions = [];
    let inviteGenerated = false;
    let draftTimer = null;
    let applyingDraft = false;
    const draftFields = ['job_id', 'company_name', 'job_title', 'work_location', 'question_bank', 'salary_min_k', 'salary_max_k', 'salary_unit', 'benefits', 'company_intro', 'responsibilities', 'requirements'];
    const draftKeyFor = (id = jobId) => id ? `job:${id}` : 'new';
    const localDraftKeyFor = (id = jobId) => `hi_hr_job_draft_${draftKeyFor(id)}`;
    const draftStatus = document.createElement('small');
    draftStatus.className = 'auto-draft-status';
    draftStatus.style.cssText = 'display:block;margin-top:10px;color:#64748b;font-size:13px;';
    form.appendChild(draftStatus);

    const setDraftStatus = (text, ok = true) => {
      draftStatus.textContent = text;
      draftStatus.style.color = ok ? '#64748b' : '#ef4444';
    };

    const normalizeDraftPayload = (payload = {}) => {
      const normalized = {};
      draftFields.forEach((key) => {
        if (payload[key] !== undefined && payload[key] !== null) normalized[key] = payload[key];
      });
      normalized._saved_at = payload._saved_at || new Date().toISOString();
      return normalized;
    };

    const hasMeaningfulDraft = (payload) => !!payload && draftFields.some((key) => String(payload[key] || '').trim());

    const saveDraftLocal = (payload, id = jobId) => {
      try { localStorage.setItem(localDraftKeyFor(id), JSON.stringify(normalizeDraftPayload(payload))); } catch (err) {}
    };

    const loadDraftLocal = (id = jobId) => {
      try { return JSON.parse(localStorage.getItem(localDraftKeyFor(id)) || 'null'); } catch (err) { return null; }
    };

    const saveDraftRemote = async (payload, id = jobId) => {
      await post('hr/job_draft.php', {draft_key: draftKeyFor(id), payload: normalizeDraftPayload(payload)});
    };

    const loadDraftRemote = async (id = jobId) => {
      try {
        const res = await get(`hr/job_draft.php?draft_key=${encodeURIComponent(draftKeyFor(id))}`);
        return res.data?.payload || res.data?.draft || null;
      } catch (err) {
        return null;
      }
    };

    const clearDraft = async (id = jobId) => {
      try { localStorage.removeItem(localDraftKeyFor(id)); } catch (err) {}
      try { await post('hr/job_draft.php', {action: 'clear', draft_key: draftKeyFor(id)}); } catch (err) {}
    };

    const applyDraftToForm = (payload) => {
      if (!hasMeaningfulDraft(payload)) return false;
      applyingDraft = true;
      draftFields.forEach((key) => {
        if (key === 'job_id') return;
        const field = form.querySelector(`[name="${key}"]`);
        if (field && payload[key] !== undefined && payload[key] !== null) {
          field.value = payload[key];
          if (key === 'benefits') field.dispatchEvent(new Event('input', {bubbles: true}));
          if (key === 'salary_unit') field.dispatchEvent(new Event('change', {bubbles: true}));
        }
      });
      savedPayload = readForm(form);
      updateCandidatePreview(savedPayload);
      applyingDraft = false;
      return true;
    };

    const newestDraft = (items) => items
      .filter(hasMeaningfulDraft)
      .sort((a, b) => new Date(b._saved_at || b.saved_at || 0) - new Date(a._saved_at || a.saved_at || 0))[0] || null;

    const restoreDraftIfAny = async () => {
      const draft = newestDraft([loadDraftLocal(), await loadDraftRemote()]);
      if (draft && applyDraftToForm(draft)) setDraftStatus('已恢复上次未保存的草稿，点击“保存岗位信息”后正式生效。');
      else setDraftStatus('草稿自动保存已开启。');
    };

    const scheduleAutoDraft = () => {
      if (applyingDraft) return;
      const payload = readForm(form);
      saveDraftLocal(payload);
      setDraftStatus('草稿已自动保存到本机，正在同步到账号...');
      clearTimeout(draftTimer);
      draftTimer = setTimeout(async () => {
        try {
          await saveDraftRemote(payload);
          setDraftStatus('草稿已自动保存。');
        } catch (err) {
          setDraftStatus('草稿已保存在本机，网络恢复后请再次编辑或保存。', false);
        }
      }, 800);
    };

    const renderQuestionLists = () => {
      renderEditableQuestions(basicList, basicQuestions, () => {});
      renderEditableQuestions(jobList, jobQuestions, () => {});
    };

    const render = () => {
      stepButtons.forEach((button) => button.classList.toggle('active', Number(button.dataset.step) === current));
      panels.forEach((panel) => panel.classList.toggle('active', Number(panel.dataset.panel) === current));
      if (prev) prev.disabled = current === 1;
      if (next) next.textContent = current === panels.length ? '完成并进入初面管理' : '下一步';
      const url = new URL(location.href);
      if (jobId) url.searchParams.set('job_id', jobId);
      url.searchParams.set('step', String(current));
      history.replaceState(null, '', url.pathname + url.search);
    };

    const saveJobIfNeeded = async () => {
      const payload = readForm(form);
      validateJob(payload);
      const oldJobId = jobId;
      const wasUpdate = !!jobId;
      if (wasUpdate) payload.job_id = jobId;
      showMessage(wasUpdate ? '正在更新岗位信息...' : '正在保存岗位信息...', true);
      const res = await post('hr/create_job.php', payload);
      jobId = String(res.data.job_id);
      savedPayload = payload;
      updateCandidatePreview(payload);
      if (openBank) {
        openBank.href = `./question-bank.html?job_id=${encodeURIComponent(jobId)}&return=create-interview-flow.html%3Fv%3D20260615cachefix&step=2`;
        openBank.hidden = false;
      }
      await clearDraft(oldJobId);
      await clearDraft(jobId);
      setDraftStatus('岗位信息已保存，临时草稿已清除。');
      showMessage(wasUpdate ? '岗位信息已更新。现在可以确认面试问题。' : '岗位信息已保存。现在可以确认面试问题。', true);
      return jobId;
    };

    const loadExistingJob = async () => {
      if (!jobId) return;
      try {
        const res = await get(`hr/job_detail.php?job_id=${encodeURIComponent(jobId)}`);
        const job = res.data.job || {};
        applyingDraft = true;
        Object.keys(job).forEach((key) => {
          const field = form.querySelector(`[name="${key}"]`);
          if (field) {
            field.value = job[key] || '';
            if (key === 'benefits') field.dispatchEvent(new Event('input', {bubbles: true}));
            if (key === 'salary_unit') field.dispatchEvent(new Event('change', {bubbles: true}));
          }
        });
        applyingDraft = false;
        savedPayload = readForm(form);
        updateCandidatePreview(savedPayload);
        if (openBank) {
          openBank.href = `./question-bank.html?job_id=${encodeURIComponent(jobId)}&return=create-interview-flow.html%3Fv%3D20260615cachefix&step=2`;
          openBank.hidden = false;
        }
        const questions = await get(`ai/list_questions.php?job_id=${encodeURIComponent(jobId)}`);
        jobQuestions = (questions.data.questions || []).map((item) => ({
          question: item.question_text,
          type: item.question_type || '岗位匹配题',
          difficulty: item.difficulty || '中等',
          purpose: item.purpose || '验证岗位匹配度',
          is_required: Number(item.is_required) === 1
        }));
        renderQuestionLists();
      } catch (err) {
        applyingDraft = false;
        showMessage(err.message);
      }
    };

    const generateQuestions = async () => {
      await saveJobIfNeeded();
      if (!generateBtn) return;
      generateBtn.disabled = true;
      generateBtn.textContent = 'AI生成中...';
      showMessage('AI正在生成岗位匹配题，请稍等。', true);
      try {
        const res = await post('ai/generate_questions.php', {job_id: jobId});
        jobQuestions = (res.data.questions || []).map((item) => ({
          question: item.question,
          type: item.type || '岗位匹配题',
          difficulty: item.difficulty || '中等',
          purpose: item.purpose || '验证岗位匹配度',
          is_required: !!item.is_required
        }));
        renderQuestionLists();
        showMessage('AI岗位题已生成，可以在本页直接编辑。', true);
        generateBtn.textContent = '重新生成岗位题';
      } finally {
        generateBtn.disabled = false;
        if (generateBtn.textContent === 'AI生成中...') generateBtn.textContent = 'AI生成岗位题';
      }
    };

    const hasEnoughQuestions = () => {
      const validBasic = basicQuestions.filter((item) => String(item.question || '').trim()).length;
      const validJob = jobQuestions.filter((item) => String(item.question || '').trim()).length;
      return validBasic >= 3 && validJob >= 1;
    };

    const saveQuestions = async () => {
      if (!hasEnoughQuestions()) {
        throw new Error('请先确认至少3道基础必问题，并生成或添加岗位匹配题');
      }
      const questions = [...basicQuestions, ...jobQuestions]
        .map((item) => ({...item, question: String(item.question || '').trim()}))
        .filter((item) => item.question);
      await post('ai/save_questions.php', {job_id: jobId, questions});
    };

    const createInvite = async () => {
      await saveJobIfNeeded();
      await saveQuestions();
      if (!createInviteBtn) return;
      createInviteBtn.disabled = true;
      createInviteBtn.textContent = '正在生成...';
      try {
        const res = await post('hr/create_invite.php', {job_id: jobId});
        const link = res.data.link || '';
        if (inviteLink) inviteLink.textContent = link;
        if (inviteText) {
          const title = savedPayload?.job_title || form.querySelector('[name="job_title"]')?.value || '岗位';
          inviteText.textContent = `您好，您收到一份${title}的线上初面邀请。请在有效期内完成手机号验证、实名授权、简历上传与AI初面：${link}`;
        }
        inviteGenerated = true;
        showMessage('正式面试链接已生成。', true);
      } finally {
        createInviteBtn.disabled = false;
        createInviteBtn.textContent = inviteGenerated ? '重新生成一个链接' : '生成正式面试链接';
      }
    };

    addBasicBtn?.addEventListener('click', () => {
      basicQuestions.push(questionItem('请填写你想固定询问的问题。', '基础必问题', true));
      renderQuestionLists();
    });

    stepButtons.forEach((button) => {
      button.addEventListener('click', async () => {
        const target = Number(button.dataset.step);
        try {
        if (target > 1) await saveJobIfNeeded();
          if (target > 2) await saveQuestions();
          current = target;
          render();
        } catch (err) {
          showMessage(err.message);
        }
      });
    });

    prev?.addEventListener('click', () => {
      current = Math.max(1, current - 1);
      render();
    });

    next?.addEventListener('click', async () => {
      try {
        if (current === 1) await saveJobIfNeeded();
        if (current === 2) await saveQuestions();
        if (current === panels.length) {
          location.href = './job-management.html';
          return;
        }
        current = Math.min(panels.length, current + 1);
        render();
      } catch (err) {
        showMessage(err.message);
      }
    });

    form.addEventListener('input', () => {
      savedPayload = readForm(form);
      updateCandidatePreview(savedPayload);
      scheduleAutoDraft();
    });

    form.addEventListener('change', () => {
      savedPayload = readForm(form);
      updateCandidatePreview(savedPayload);
      scheduleAutoDraft();
    });

    generateBtn?.addEventListener('click', () => {
      generateQuestions().catch((err) => showMessage(err.message));
    });

    createInviteBtn?.addEventListener('click', () => {
      createInvite().catch((err) => showMessage(err.message));
    });

    setupCopyButtons();
    renderQuestionLists();
    loadExistingJob().then(restoreDraftIfAny).then(render);
    render();
  }

  setupDailyQuote();
  setupDashboardGuide();
  setupCopyButtons();
  setupCreateInterviewWizard();
})();
