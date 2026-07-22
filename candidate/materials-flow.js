(() => {
  const API = '../api';
  const page = location.pathname.split('/').pop() || 'index.html';
  const params = new URLSearchParams(location.search);
  const token = () => params.get('token') || localStorage.getItem('candidate_token') || '';
  const sessionId = () => params.get('session_id') || '';
  const $ = (sel, root = document) => root.querySelector(sel);

  function escapeHtml(text) {
    return String(text ?? '').replace(/[&<>"']/g, (ch) => ({
      '&': '&amp;',
      '<': '&lt;',
      '>': '&gt;',
      '"': '&quot;',
      "'": '&#39;',
    }[ch]));
  }

  async function post(path, payload) {
    const res = await fetch(`${API}/${path}`, {
      method: 'POST',
      headers: { 'Content-Type': 'application/json' },
      credentials: 'include',
      body: JSON.stringify(payload),
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

  const statusText = (status) => ({
    pending: '等待处理',
    processing: '处理中',
    completed: '已完成',
    ready: '已完成',
    failed: '处理失败',
    unsupported: '暂不支持',
  }[status] || '等待处理');

  function setProcessingStatus(data) {
    const merge = $('#mergeStatus');
    const transcript = $('#transcriptStatus');
    const report = $('#reportStatus');
    if (merge) merge.textContent = statusText(data.merge_status);
    if (transcript) transcript.textContent = statusText(data.transcript_status);
    if (report) report.textContent = statusText(data.report_status);
  }

  async function runProcessing() {
    if (page !== 'processing.html') return;
    const message = $('#processingMessage');
    const retry = $('#retryProcessing');
    const t = token();
    const sid = sessionId();
    if (!t || !sid) {
      if (message) message.textContent = '面试链接缺少必要参数，请从原邀请链接重新进入。';
      return;
    }

    retry?.setAttribute('disabled', 'disabled');
    if (message) {
      message.classList.add('ok');
      message.textContent = '系统正在整理录音、转写和报告，请稍等...';
    }

    try {
      const res = await post('candidate/process_materials.php', { token: t, session_id: sid });
      setProcessingStatus(res.data || {});
      if (res.data?.done) {
        location.href = res.data.next || `complete.html?token=${encodeURIComponent(t)}&session_id=${encodeURIComponent(sid)}`;
        return;
      }
      if (message) message.textContent = '材料仍在整理中，系统将继续检查。';
      setTimeout(runProcessing, 5000);
    } catch (err) {
      if (message) {
        message.classList.remove('ok');
        message.textContent = err.message || '材料整理失败，请稍后重试。';
      }
      retry?.removeAttribute('disabled');
    }
  }

  function wrapCanvasText(ctx, text, x, y, maxWidth, lineHeight) {
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
  }

  function setupCompleteOverride() {
    if (page !== 'complete.html') return;
    const t = token();
    const sid = sessionId();
    const list = $('#transcriptList');
    const imageBtn = $('#saveTranscriptImage');
    const downloadList = $('#audioDownloads');
    const audioMessage = $('#audioMessage');
    if (!list || !t) return;

    let transcriptItems = [];
    const renderImage = () => {
      if (!transcriptItems.length) return;
      const width = 900;
      const padding = 44;
      const lineHeight = 30;
      const maxWidth = width - padding * 2;
      const canvas = document.createElement('canvas');
      const ctx = canvas.getContext('2d');
      ctx.font = '22px Microsoft YaHei, sans-serif';
      let height = 130;
      transcriptItems.forEach((item) => {
        height += 58;
        height += Math.max(1, Math.ceil(ctx.measureText(item.question).width / maxWidth)) * lineHeight;
        height += Math.max(1, Math.ceil(ctx.measureText(item.answer).width / maxWidth)) * lineHeight;
        height += 38;
      });
      canvas.width = width;
      canvas.height = Math.max(460, height);

      ctx.fillStyle = '#f5fafb';
      ctx.fillRect(0, 0, canvas.width, canvas.height);
      ctx.fillStyle = '#174d7c';
      ctx.font = 'bold 34px Microsoft YaHei, sans-serif';
      ctx.fillText('AI全量初面系统｜面试转写记录', padding, 66);
      ctx.fillStyle = '#5b6b7a';
      ctx.font = '18px Microsoft YaHei, sans-serif';
      ctx.fillText('仅供候选人本人复盘使用，不包含岗位匹配度、推进建议或HR复核结果。', padding, 100);

      let y = 150;
      transcriptItems.forEach((item, idx) => {
        ctx.fillStyle = '#ffffff';
        ctx.fillRect(padding - 16, y - 30, width - padding * 2 + 32, 38);
        ctx.fillStyle = '#174d7c';
        ctx.font = 'bold 22px Microsoft YaHei, sans-serif';
        ctx.fillText(`第 ${idx + 1} 题`, padding, y);
        y += 40;

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
      a.download = `AI初面转写记录-${sid || Date.now()}.png`;
      a.click();
    };

    imageBtn?.addEventListener('click', renderImage);

    get(`candidate/interview_result.php?token=${encodeURIComponent(t)}&session_id=${encodeURIComponent(sid)}`).then(({ data }) => {
      const recordings = data.recordings || [];
      const answers = data.answers || [];
      const isAudio = data.interview_type === 'audio';
      const materials = data.materials || {};

      transcriptItems = [];
      if (isAudio && recordings.length) {
        list.innerHTML = recordings.map((item) => {
          const transcriptText = item.transcript_text || '';
          const status = item.transcript_status === 'completed' ? '已转写'
            : item.transcript_status === 'failed' ? '转写失败'
              : '转写处理中';
          transcriptItems.push({
            question: item.question_text || '面试问题',
            answer: transcriptText || `语音回答（${item.audio_seconds || 0}秒），${status}`,
          });
          return `
            <div class="dialog"><span>AI</span><strong>${escapeHtml(item.question_text || '面试问题')}</strong></div>
            <div class="dialog"><span>候选人</span>${
              transcriptText
                ? `<p>${escapeHtml(transcriptText)}</p>`
                : `<p class="soft-note">语音回答（${item.audio_seconds || 0}秒），${status}</p>`
            }</div>
          `;
        }).join('');
      } else if (answers.length) {
        list.innerHTML = answers.map((item) => `
          <div class="dialog"><span>AI</span><strong>${escapeHtml(item.question_text)}</strong></div>
          <div class="dialog"><span>候选人</span><p>${escapeHtml(item.answer_text)}</p></div>
        `).join('');
        transcriptItems = answers.map((item) => ({ question: item.question_text, answer: item.answer_text }));
      } else {
        list.innerHTML = '<div class="soft-note">暂无可查看的面试复盘记录。</div>';
      }

      if (imageBtn) imageBtn.disabled = transcriptItems.length === 0;
      if (audioMessage) audioMessage.textContent = data.audio_message || '面试记录已保存。';

      if (downloadList) {
        const mergedUrl = materials.merged_audio_download_url || '';
        if (mergedUrl) {
          downloadList.innerHTML = `
            <a class="download-item" href="${escapeHtml(mergedUrl)}" target="_blank" rel="noreferrer" download>
              下载本次面试回答录音
              <small>整段音频，链接有效期约24小时</small>
            </a>
          `;
        } else if (materials.merge_status === 'unsupported') {
          downloadList.innerHTML = '<div class="download-item disabled">整段录音暂未生成：服务器未安装录音合并服务。</div>';
        } else if (materials.merge_status === 'failed') {
          downloadList.innerHTML = '<div class="download-item disabled">整段录音暂未生成成功，HR端仍可按题复核录音。</div>';
        } else {
          downloadList.innerHTML = '<div class="download-item disabled">整段录音正在整理中，请稍后刷新。</div>';
        }
      }
    }).catch((err) => {
      list.innerHTML = `<div class="form-message">${escapeHtml(err.message || '读取面试材料失败')}</div>`;
    });
  }

  $('#retryProcessing')?.addEventListener('click', runProcessing);
  runProcessing();
  setupCompleteOverride();
})();
