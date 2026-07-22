(() => {
  const page = location.pathname.split('/').pop() || 'index.html';
  const params = new URLSearchParams(location.search);
  // 只有面试流程中（from=interview 或 URL 带 token）才显示进度条
  const inInterviewFlow = params.get('from') === 'interview' || params.get('token') || params.get('invite_token');
  const map = {
    'index.html': { step: 1, title: '确认企业和岗位' },
    'job-confirm.html': { step: 1, title: '确认企业和岗位' },
    'login.html': { step: 2, title: '手机号验证与实名认证' },
    'register.html': { step: 2, title: '手机号验证与实名认证' },
    'auth.html': { step: 2, title: '手机号验证与实名认证' },
    'agreement.html': { step: 2, title: '手机号验证与实名认证' },
    'resume.html': { step: 3, title: '上传简历' },
    'device-check.html': { step: 4, title: '设备检查' },
    'interview.html': { step: 5, title: '开始面试' },
    'complete.html': { step: 5, title: '面试完成' }
  };
  const item = map[page];
  const top = document.querySelector('.top');
  if (!item || !top || document.querySelector('.flow-progress')) return;
  // login.html 只在面试流程中显示进度条
  if (page === 'login.html' && !inInterviewFlow) return;
  const done = page === 'complete.html';
  const totalSteps = 5;
  const percent = done ? 100 : Math.round((item.step / totalSteps) * 100);
  const progress = document.createElement('section');
  progress.className = 'flow-progress';
  progress.innerHTML = `
    <div class="flow-progress-head">
      <strong>线上初面 · 预计8-15分钟</strong>
      <span>${done ? '已完成' : `第${item.step}步/${totalSteps}步`}</span>
    </div>
    <div class="flow-progress-bar"><i style="width:${percent}%"></i></div>
    <p>${item.title}</p>
  `;
  top.insertAdjacentElement('afterend', progress);
})();
