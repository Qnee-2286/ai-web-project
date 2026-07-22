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
    const data = await response.json().catch(() => ({ok: false, message: 'API response format error'}));
    if (data?.data?.need_login) {
      const _cur = 'hr/' + (location.pathname.split('/').pop() || '') + location.search;
      location.href = './login.html?redirect=' + encodeURIComponent(_cur);
      return new Promise(() => {});
    }
    if (!response.ok || !data.ok) {
      const error = new Error(data.message || 'Request failed');
      error.data = data.data || {};
      error.status = response.status;
      throw error;
    }
    return data;
  }

  async function get(path) {
    const response = await fetch(`${API}/${path}`, {credentials: 'include'});
    const data = await response.json().catch(() => ({ok: false, message: 'API response format error'}));
    if (data?.data?.need_login) {
      const _cur = 'hr/' + (location.pathname.split('/').pop() || '') + location.search;
      location.href = './login.html?redirect=' + encodeURIComponent(_cur);
      return new Promise(() => {});
    }
    if (!response.ok || !data.ok) {
      const error = new Error(data.message || 'Request failed');
      error.data = data.data || {};
      error.status = response.status;
      throw error;
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
    const type = jobTypeLabels[job?.job_type] || '';
    const direction = String(job?.job_direction || '').trim();
    const level = jobLevelLabels[job?.job_level] || '';
    const taxonomy = [type || job?.job_type, direction, level].filter(Boolean).join(' · ');
    return taxonomy || String(job?.work_location || '').trim() || '岗位类型待补充';
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
    const payload = Object.fromEntries(new FormData(form).entries());
    return normalizeJobTaxonomyPayload(payload);
  }

  const jobDirectionOptions = {
    sales_business: ['电话销售', '网络销售', '面销/地推', '渠道销售', '大客户销售', 'BD/商务拓展', '销售助理', '销售运营', '销售管理'],
    marketing_operation: ['新媒体运营', '内容运营', '社群运营', '用户运营', '电商运营', '活动运营', '直播运营', '品牌/市场推广', '投放/推广', '运营管理'],
    customer_service: ['在线客服', '电话客服', '售前客服', '售后客服', '投诉处理', '客户成功', '前台/接待', '服务顾问', '客服主管'],
    retail_store: ['店员/营业员', '收银员', '理货员', '导购', '促销员', '店助/副店长', '店长', '督导', '陈列/巡店'],
    logistics_delivery: ['仓库管理员', '分拣员', '打包员', '搬运/装卸', '配送员', '快递员', '司机', '调度', '物流客服', '仓储主管'],
    production_manufacturing: ['普工/操作工', '装配工', '包装工', '质检员', '机修/设备维护', '生产文员', '班组长', '生产主管', '工艺/技术员'],
    food_hospitality: ['服务员', '传菜员', '收银/迎宾', '后厨/打荷', '厨师/厨工', '咖啡/茶饮师', '客房服务', '前台接待', '店长/主管'],
    life_property_service: ['保洁', '保安', '月嫂/育儿嫂', '家政服务', '维修工', '绿化工', '物业客服', '物业管家', '物业主管'],
    administration: ['行政专员', '行政前台', '文员/助理', '资料员', '采购', '后勤', '总务', '司机', '行政主管'],
    human_resources: ['招聘专员', '员工关系', '培训专员', '薪酬绩效', '人事行政', 'HRBP', '人事主管/经理'],
    finance_legal: ['出纳', '会计', '财务助理', '财务专员', '税务', '审计', '风控', '法务专员', '财务主管/经理'],
    tech_rd: ['前端开发', '后端开发', '全栈开发', '测试', '运维', '数据分析', '算法/AI', '技术支持', 'IT工程师', '研发管理'],
    product_design: ['产品助理', '产品经理', '项目经理', 'UI设计', '平面设计', '视觉设计', '视频剪辑', '摄影/摄像', '设计主管'],
    education_training: ['课程顾问', '班主任/学管', '助教', '讲师/培训师', '幼教', '职业培训', '教务', '校区主管'],
    medical_health: ['护士', '导医', '药店店员', '健康顾问', '医美咨询', '康复理疗', '护理员', '门诊客服'],
    construction_property: ['施工员', '资料员', '安全员', '监理', '预算/造价', '工程助理', '物业维修', '项目主管'],
    flexible_parttime: ['临促', '兼职店员', '兼职客服', '兼职地推', '兼职分拣', '小时工', '日结工', '活动执行'],
    other: ['其他', '暂不确定']
  };

  function normalizeJobTaxonomyPayload(payload) {
    const next = {...payload};
    if (next.job_type === 'custom') {
      next.job_type = String(next.job_type_custom || '').trim();
    }
    if (next.job_direction === 'custom') {
      next.job_direction = String(next.job_direction_custom || '').trim();
    }
    return next;
  }

  function setupJobTaxonomy(root = document) {
    root.querySelectorAll('select[data-taxonomy-type]').forEach((typeSelect) => {
      if (typeSelect.dataset.taxonomyReady) return;
      typeSelect.dataset.taxonomyReady = '1';
      const form = typeSelect.closest('form') || root;
      const directionSelect = form.querySelector('select[data-taxonomy-direction]');
      const typeCustom = form.querySelector('[data-taxonomy-type-custom]');
      const directionCustom = form.querySelector('[data-taxonomy-direction-custom]');
      if (!directionSelect) return;

      const refreshCustom = () => {
        if (typeCustom) {
          typeCustom.hidden = typeSelect.value !== 'custom';
          typeCustom.required = typeSelect.value === 'custom';
        }
        if (directionCustom) {
          directionCustom.hidden = directionSelect.value !== 'custom';
          directionCustom.required = directionSelect.value === 'custom';
        }
      };
      const fillDirections = (keep = '') => {
        const selectedType = typeSelect.value;
        const options = jobDirectionOptions[selectedType] || [];
        directionSelect.innerHTML = '';
        const first = document.createElement('option');
        first.value = '';
        first.textContent = selectedType ? '请选择岗位方向' : '请先选择岗位大类';
        directionSelect.appendChild(first);
        options.forEach((label) => {
          const option = document.createElement('option');
          option.value = label;
          option.textContent = label;
          directionSelect.appendChild(option);
        });
        const custom = document.createElement('option');
        custom.value = 'custom';
        custom.textContent = '自定义方向';
        directionSelect.appendChild(custom);
        if (keep && Array.from(directionSelect.options).some((option) => option.value === keep)) {
          directionSelect.value = keep;
        }
        refreshCustom();
      };
      typeSelect.addEventListener('change', () => fillDirections());
      directionSelect.addEventListener('change', refreshCustom);
      fillDirections(directionSelect.dataset.initialValue || directionSelect.value);
    });
  }

  function validateJob(payload) {
    const required = [
      ['company_name', '公司名称'],
      ['job_title', '岗位名称'],
      ['job_type', '岗位大类'],
      ['job_direction', '岗位方向'],
      ['job_level', '岗位层级'],
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

  function renderEditableQuestions(container, questions, onChange, offset = 0) {
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
          <span>第 ${offset + index + 1} 题</span>
          <small>${item.type || '面试问题'} · ${item.difficulty || '中等'}</small>
          <small>${item.is_required ? '必问' : '备选'}</small>
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
        renderEditableQuestions(container, questions, onChange, offset);
        onChange?.();
      });
      row.querySelector('[data-action="delete"]').addEventListener('click', () => {
        questions.splice(index, 1);
        renderEditableQuestions(container, questions, onChange, offset);
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

  const jobTypeLabels = {
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

  const jobLevelLabels = {
    intern_parttime: '实习/兼职',
    entry_staff: '基层员工',
    senior_staff: '资深员工',
    lead_supervisor: '主管/组长',
    manager: '经理',
    director_plus: '总监及以上',
    staff: '基层员工',
    management: '经理'
  };

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

  function renderInviteQr(link) {
    const box = document.getElementById('flowInviteQr');
    if (!box) return;
    const clean = String(link || '').trim();
    if (!clean) {
      box.textContent = '生成后显示二维码';
      return;
    }
    if (typeof qrcode !== 'function') {
      box.textContent = '二维码库加载失败';
      return;
    }
    const qr = qrcode(0, 'M');
    qr.addData(clean);
    qr.make();
    box.innerHTML = qr.createSvgTag(4, 0, '候选人面试二维码', '候选人面试二维码');
  }

  function setupCreateInterviewWizard() {
    if (page !== 'create-interview-flow.html') return;
    const wizard = $('#createInterviewWizard');
    const form = $('#interviewJobForm');
    if (!wizard || !form) return;
    disableAutocomplete(form);
    setupBenefitPicker(form);

    const stepButtons = Array.from(wizard.querySelectorAll('[data-step]'));
    const panels = Array.from(wizard.querySelectorAll('[data-panel]'));
    const prev = $('#wizardPrev');
    const next = $('#wizardNext');
    const generateBtn = $('#flowGenerateQuestions');
    const addBasicBtn = $('#flowAddBasicQuestion');
    const saveJobBtn = $('#flowSaveJob');
    const saveQuestionsBtn = $('#flowSaveQuestions');
    const questionCountTip = $('#flowQuestionCount');
    const linkRefreshNote = $('#flowLinkRefreshNote');
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
    let jobSaved = !!jobId;
    let questionsSaved = false;
    let contentDirtyAfterInvite = false;
    let draftTimer = null;
    let applyingDraft = false;
    const draftFields = ['job_id', 'company_name', 'job_title', 'job_type', 'job_type_custom', 'job_direction', 'job_direction_custom', 'job_level', 'work_location', 'question_bank', 'salary_min_k', 'salary_max_k', 'salary_unit', 'benefits', 'company_intro', 'responsibilities', 'requirements'];
    const draftKeyFor = (id = jobId) => id ? `job:${id}` : 'new';
    const localDraftKeyFor = (id = jobId) => `hi_hr_job_draft_${draftKeyFor(id)}`;
    const draftStatus = document.createElement('small');
    draftStatus.className = 'auto-draft-status';
    draftStatus.style.cssText = 'display:block;margin-top:10px;color:#64748b;font-size:13px;';
    (saveJobBtn?.closest('.save-action-row') || form).appendChild(draftStatus);

    const setDraftStatus = (text, ok = true) => {
      draftStatus.textContent = text;
      draftStatus.style.color = ok ? '#64748b' : '#ef4444';
    };

    setupJobTaxonomy(form);

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
          if (key === 'job_type') field.dispatchEvent(new Event('change', {bubbles: true}));
          if (key === 'job_direction') field.dispatchEvent(new Event('change', {bubbles: true}));
          if (key === 'benefits') field.dispatchEvent(new Event('input', {bubbles: true}));
          if (key === 'salary_unit') field.dispatchEvent(new Event('change', {bubbles: true}));
        }
      });
      savedPayload = readForm(form);
      jobSaved = !!jobId;
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

    const questionCount = () => [...basicQuestions, ...jobQuestions]
      .filter((item) => String(item.question || item.question_text || '').trim()).length;

    const markContentChanged = () => {
      if (inviteGenerated) {
        contentDirtyAfterInvite = true;
        if (linkRefreshNote) linkRefreshNote.hidden = false;
      }
    };

    const updateQuestionCount = () => {
      const count = questionCount();
      if (questionCountTip) {
        questionCountTip.textContent = `当前共 ${count} 题，保存范围 1-13 题。`;
        questionCountTip.classList.toggle('warn', count > 13 || count < 1);
      }
    };

    const onQuestionsChanged = () => {
      questionsSaved = false;
      markContentChanged();
      updateQuestionCount();
    };

    const renderQuestionLists = () => {
      renderEditableQuestions(basicList, basicQuestions, onQuestionsChanged, 0);
      renderEditableQuestions(jobList, jobQuestions, onQuestionsChanged, basicQuestions.length);
      updateQuestionCount();
    };

    const render = () => {
      stepButtons.forEach((button) => {
        const step = Number(button.dataset.step);
        button.classList.toggle('active', step === current);
        button.classList.toggle('done', step < current);
        const num = button.querySelector('span');
        if (num) num.textContent = step < current ? '✓' : String(step);
      });
      panels.forEach((panel) => panel.classList.toggle('active', Number(panel.dataset.panel) === current));
      if (prev) prev.disabled = current === 1;
      if (next) next.textContent = current === panels.length ? '完成并进入初面管理' : '下一步 →';
      if (linkRefreshNote) linkRefreshNote.hidden = !contentDirtyAfterInvite;
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
      jobSaved = true;
      markContentChanged();
      updateCandidatePreview(payload);
      if (openBank) {
        openBank.href = `./question-bank.html?job_id=${encodeURIComponent(jobId)}&return=create-interview-flow.html%3Fv%3D20260615cachefix&step=2`;
        openBank.hidden = false;
      }
      await clearDraft(oldJobId);
      await clearDraft(jobId);
      setDraftStatus('岗位信息已保存，临时草稿已清除。');
      showMessage(wasUpdate ? '岗位信息已保存。现在可以确认面试问题。' : '岗位信息已保存。现在可以确认面试问题。', true);
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
        jobSaved = true;
        updateCandidatePreview(savedPayload);
        if (openBank) {
          openBank.href = `./question-bank.html?job_id=${encodeURIComponent(jobId)}&return=create-interview-flow.html%3Fv%3D20260615cachefix&step=2`;
          openBank.hidden = false;
        }
        const savedQuestions = (await get(`ai/list_questions.php?job_id=${encodeURIComponent(jobId)}`)).data.questions || [];
        if (savedQuestions.length) {
          basicQuestions = savedQuestions
            .filter((item) => (item.question_type || '').includes('基础') || (item.question_type || '').includes('必问'))
            .map((item) => ({
              question: item.question_text,
              type: item.question_type || '基础必问题',
              difficulty: item.difficulty || '基础',
              purpose: item.purpose || '确认基础情况和求职意向',
              is_required: Number(item.is_required) === 1
            }));
          jobQuestions = savedQuestions
            .filter((item) => !((item.question_type || '').includes('基础') || (item.question_type || '').includes('必问')))
            .map((item) => ({
              question: item.question_text,
              type: item.question_type || '岗位匹配题',
              difficulty: item.difficulty || '中等',
              purpose: item.purpose || '验证岗位匹配度',
              is_required: Number(item.is_required) === 1
            }));
          questionsSaved = true;
        } else {
          questionsSaved = false;
        }
        renderQuestionLists();
      } catch (err) {
        applyingDraft = false;
        showMessage(err.message);
      }
    };

    const generateQuestions = async () => {
      if (!jobSaved || !jobId) throw new Error('请先保存岗位信息，再生成面试问题');
      if (!generateBtn) return;
      generateBtn.disabled = true;
      generateBtn.textContent = 'AI生成中...';
      showMessage('AI正在生成岗位匹配题，请稍等。', true);
      try {
        const res = await post('ai/generate_questions.php', {job_id: jobId});
        const remaining = Math.max(0, 13 - basicQuestions.filter((item) => String(item.question || '').trim()).length);
        if (remaining <= 0) throw new Error('基础问题已达到13题，请先删除部分题目后再生成岗位题');
        jobQuestions = (res.data.questions || []).slice(0, remaining).map((item) => ({
          question: item.question,
          type: item.type || '岗位匹配题',
          difficulty: item.difficulty || '中等',
          purpose: item.purpose || '验证岗位匹配度',
          is_required: !!item.is_required
        }));
        questionsSaved = false;
        markContentChanged();
        renderQuestionLists();
        showMessage('AI岗位题已生成，可以在本页直接编辑。', true);
        generateBtn.textContent = '重新生成岗位题';
      } finally {
        generateBtn.disabled = false;
        if (generateBtn.textContent === 'AI生成中...') generateBtn.textContent = 'AI生成岗位题';
      }
    };

    const saveQuestions = async (askConfirm = false) => {
      const questions = [...basicQuestions, ...jobQuestions]
        .map((item) => ({...item, question: String(item.question || '').trim()}))
        .filter((item) => item.question);
      if (questions.length < 1) throw new Error('请至少保留1道有效问题');
      if (questions.length > 13) throw new Error(`当前共 ${questions.length} 题，一场初面最多13题，请删除部分题目后再保存`);
      if (askConfirm && !window.confirm(`该岗位初面题目一共 ${questions.length} 题，请确认。`)) return false;
      await post('ai/save_questions.php', {job_id: jobId, questions});
      questionsSaved = true;
      markContentChanged();
      showMessage(`面试问题已保存，共 ${questions.length} 题。`, true);
      return true;
    };

    const createInvite = async () => {
      if (!jobSaved || !jobId) throw new Error('请先保存岗位信息，再生成链接');
      if (!questionsSaved) throw new Error('请先保存面试问题，再生成链接');
      if (!createInviteBtn) return;
      createInviteBtn.disabled = true;
      createInviteBtn.textContent = '正在生成...';
      try {
        const res = await post('hr/create_invite.php', {job_id: jobId});
        const link = res.data.link || '';
        if (inviteLink) inviteLink.textContent = link;
        renderInviteQr(link);
        if (inviteText) {
          const title = savedPayload?.job_title || form.querySelector('[name="job_title"]')?.value || '岗位';
          inviteText.textContent = `您好，您收到一份${title}的线上初面邀请。请在有效期内完成手机号验证、实名授权、简历上传与AI初面：${link}`;
        }
        inviteGenerated = true;
        contentDirtyAfterInvite = false;
        if (linkRefreshNote) linkRefreshNote.hidden = true;
        showMessage('正式面试链接已生成。', true);
      } finally {
        createInviteBtn.disabled = false;
        createInviteBtn.textContent = inviteGenerated ? '重新生成一个链接' : '生成正式面试链接';
      }
    };

    addBasicBtn?.addEventListener('click', () => {
      if (questionCount() >= 13) {
        showMessage('一场初面最多13题，请先删除部分题目。');
        return;
      }
      basicQuestions.push(questionItem('请填写你想固定询问的问题。', '基础必问题', true));
      questionsSaved = false;
      markContentChanged();
      renderQuestionLists();
    });

    saveJobBtn?.addEventListener('click', async () => {
      try {
        saveJobBtn.disabled = true;
        await saveJobIfNeeded();
      } catch (err) {
        showMessage(err.message);
      } finally {
        saveJobBtn.disabled = false;
      }
    });

    saveQuestionsBtn?.addEventListener('click', async () => {
      try {
        if (!jobSaved || !jobId) throw new Error('请先保存岗位信息，再保存面试问题');
        saveQuestionsBtn.disabled = true;
        await saveQuestions(true);
      } catch (err) {
        showMessage(err.message);
      } finally {
        saveQuestionsBtn.disabled = false;
      }
    });

    stepButtons.forEach((button) => {
      button.addEventListener('click', async () => {
        const target = Number(button.dataset.step);
        try {
          if (target > 1 && (!jobSaved || !jobId)) throw new Error('请先点击“保存岗位信息”，再进入下一步');
          if (target > 2 && !questionsSaved) throw new Error('请先点击“保存面试问题”，确认题目数量后再进入下一步');
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
        if (current === 1 && (!jobSaved || !jobId)) throw new Error('请先点击“保存岗位信息”，再进入下一步');
        if (current === 2 && !questionsSaved) throw new Error('请先点击“保存面试问题”，确认题目数量后再进入下一步');
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
      jobSaved = false;
      markContentChanged();
      updateCandidatePreview(savedPayload);
      scheduleAutoDraft();
    });

    form.addEventListener('change', () => {
      savedPayload = readForm(form);
      jobSaved = false;
      markContentChanged();
      updateCandidatePreview(savedPayload);
      scheduleAutoDraft();
    });

    generateBtn?.addEventListener('click', () => {
      generateQuestions().catch((err) => showMessage(err.message));
    });

    createInviteBtn?.addEventListener('click', () => {
      createInvite().catch((err) => {
        if (err?.data?.quota_required || err?.status === 402 || String(err.message || '').includes('quota')) {
          document.getElementById('quotaModal')?.classList.add('show');
          return;
        }
        showMessage(err.message);
      });
    });

    setupCopyButtons();
    renderQuestionLists();
    loadExistingJob().then(restoreDraftIfAny).then(render);
    render();
  }

  setupDailyQuote();
  setupDashboardGuide();
  setupCopyButtons();
  setupJobTaxonomy(document);
  setupCreateInterviewWizard();
})();
