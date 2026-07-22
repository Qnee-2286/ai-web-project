(() => {
  const API = '../api';
  const $ = (selector) => document.querySelector(selector);
  const escape = (value) => String(value ?? '-').replace(/[&<>"']/g, (char) => ({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;', "'":'&#39;'}[char]));
  const fmtTime = (value) => value ? String(value).replace('T', ' ').slice(0, 16) : '-';
  const statusText = (value) => ({
    not_received: '未接收',
    pending_interview: '待面试',
    interviewing: '面试中',
    completed: '已完成',
    review_pending: '待复核',
    rejected: '暂不推进'
  }[value] || value || '-');
  const realnameText = (value) => value === 'verified' ? '已实名' : value === 'failed' ? '未通过' : '待认证';

  async function get(path) {
    const response = await fetch(`${API}/${path}`, { credentials: 'include' });
    const data = await response.json().catch(() => ({ ok: false, message: '接口返回格式错误' }));
    if (data?.data?.need_login) {
      location.href = './login.html';
      return new Promise(() => {});
    }
    if (!response.ok || !data.ok) throw new Error(data.message || '请求失败');
    return data;
  }

  async function post(path, payload) {
    const response = await fetch(`${API}/${path}`, {
      method: 'POST',
      credentials: 'include',
      headers: { 'Content-Type': 'application/json' },
      body: JSON.stringify(payload)
    });
    const data = await response.json().catch(() => ({ ok: false, message: '接口返回格式错误' }));
    if (!response.ok || !data.ok) throw new Error(data.message || '请求失败');
    return data;
  }

  function showError(message) {
    $('#adminCandidateRows').innerHTML = `<tr><td colspan="8">${escape(message)}</td></tr>`;
    $('#adminHrRows').innerHTML = `<tr><td colspan="7">${escape(message)}</td></tr>`;
  }

  function render(data) {
    const summary = data.summary || {};
    $('#adminHrCount').textContent = summary.hr_count ?? 0;
    $('#adminCandidateCount').textContent = summary.candidate_count ?? 0;
    $('#adminTestCount').textContent = summary.test_candidate_count ?? 0;
    $('#adminReportCount').textContent = summary.report_count ?? 0;
    const candidateRows = $('#adminCandidateRows');
    const candidates = data.candidates || [];
    candidateRows.innerHTML = candidates.length ? '' : '<tr><td colspan="8">暂无候选人记录。</td></tr>';
    candidates.forEach((item) => {
      const row = document.createElement('tr');
      const isTest = Number(item.is_test) === 1;
      const report = item.report_id ? `<a class="primary-link" href="./report.html?report_id=${Number(item.report_id)}">查看报告</a>` : '';
      row.innerHTML = `
        <td>${escape(item.interview_no || '尚未开始面试')}</td>
        <td>${escape(item.phone || '尚未验证')}</td>
        <td>${escape(item.job_title || '-')}</td>
        <td>${escape(item.hr_name || '-')}</td>
        <td>${escape(statusText(item.candidate_status))}</td>
        <td>${escape(realnameText(item.realname_status))}</td>
        <td><span class="tag ${isTest ? 'orange' : 'green'}">${isTest ? '测试' : '正式'}</span></td>
        <td><span class="table-actions">${report}<button class="btn" type="button" data-candidate="${Number(item.id)}" data-test="${isTest ? 0 : 1}">${isTest ? '恢复正式' : '标记测试'}</button></span></td>`;
      candidateRows.appendChild(row);
    });

    const hrRows = $('#adminHrRows');
    const hrs = data.hr_accounts || [];
    hrRows.innerHTML = hrs.length ? '' : '<tr><td colspan="7">暂无HR账号。</td></tr>';
    hrs.forEach((item) => {
      const row = document.createElement('tr');
      row.innerHTML = `
        <td>${escape(item.name || '-')}</td>
        <td>${escape(item.phone || '-')}</td>
        <td>${escape(item.email || '未绑定')}</td>
        <td>${escape(realnameText(item.realname_status))}</td>
        <td>${item.company_verification_status === 'verified' ? '已认证' : '待认证'}</td>
        <td>${Number(item.is_platform_admin) === 1 ? '<span class="tag blue">管理员</span>' : 'HR'}</td>
        <td>${escape(fmtTime(item.created_at))}</td>`;
      hrRows.appendChild(row);
    });
  }

  async function load() {
    try {
      const result = await get('admin/dashboard.php');
      render(result.data || {});
    } catch (error) {
      showError(error.message);
    }
  }

  $('#adminCandidateRows')?.addEventListener('click', async (event) => {
    const button = event.target.closest('button[data-candidate]');
    if (!button) return;
    button.disabled = true;
    try {
      await post('admin/toggle_candidate_test.php', {
        candidate_id: Number(button.dataset.candidate),
        is_test: Number(button.dataset.test) === 1
      });
      await load();
    } catch (error) {
      alert(error.message);
      button.disabled = false;
    }
  });

  load();
})();
