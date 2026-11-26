// SillyLi 表白墙 V2 frontend
(function () {
  'use strict';

  var csrf = document.querySelector('meta[name="csrf-token"]').getAttribute('content');

  // ---------- theme ----------
  var root = document.documentElement;
  var saved = localStorage.getItem('fl-theme');
  if (saved) root.setAttribute('data-bs-theme', saved);
  var toggle = document.getElementById('themeToggle');
  function paintToggle() {
    if (toggle) toggle.textContent = root.getAttribute('data-bs-theme') === 'dark' ? '☀️' : '🌙';
  }
  paintToggle();
  if (toggle) {
    toggle.addEventListener('click', function () {
      var next = root.getAttribute('data-bs-theme') === 'dark' ? 'light' : 'dark';
      root.setAttribute('data-bs-theme', next);
      localStorage.setItem('fl-theme', next);
      paintToggle();
    });
  }

  // ---------- post helper ----------
  function postForm(path, formData) {
    return fetch(path, {
      method: 'POST',
      headers: { 'X-CSRF-Token': csrf },
      body: formData
    }).then(function (r) { return r.json(); });
  }

  // ---------- likes ----------
  var liked = {};
  try { liked = JSON.parse(localStorage.getItem('fl-liked') || '{}'); } catch (e) { liked = {}; }
  document.querySelectorAll('[data-like]').forEach(function (btn) {
    var id = btn.getAttribute('data-like');
    if (liked[id]) {
      btn.classList.add('liked');
      btn.querySelector('.fl-heart').textContent = '❤️';
    }
    btn.addEventListener('click', function () {
      if (liked[id]) return;
      var fd = new FormData();
      fd.append('post_id', id);
      postForm('/like', fd).then(function (res) {
        if (res.ok) {
          btn.querySelector('.fl-like-count').textContent = res.count;
          btn.classList.add('liked');
          btn.querySelector('.fl-heart').textContent = '❤️';
          liked[id] = 1;
          localStorage.setItem('fl-liked', JSON.stringify(liked));
        }
      });
    });
  });

  // ---------- publish ----------
  var publishForm = document.getElementById('publishForm');
  if (publishForm) {
    publishForm.addEventListener('submit', function (ev) {
      ev.preventDefault();
      var btn = document.getElementById('publishSubmit');
      var err = document.getElementById('publishError');
      btn.disabled = true;
      err.classList.add('d-none');
      postForm('/publish', new FormData(publishForm)).then(function (res) {
        btn.disabled = false;
        if (res.ok) {
          location.reload();
        } else {
          err.textContent = res.error || '发布失败，请重试';
          err.classList.remove('d-none');
        }
      }).catch(function () {
        btn.disabled = false;
        err.textContent = '网络异常，请重试';
        err.classList.remove('d-none');
      });
    });
  }

  // ---------- comment ----------
  var commentForm = document.getElementById('commentForm');
  if (commentForm) {
    commentForm.addEventListener('submit', function (ev) {
      ev.preventDefault();
      var btn = document.getElementById('commentSubmit');
      var err = document.getElementById('commentError');
      btn.disabled = true;
      err.classList.add('d-none');
      postForm('/comment', new FormData(commentForm)).then(function (res) {
        btn.disabled = false;
        if (res.ok) {
          var list = document.getElementById('commentList');
          var none = document.getElementById('noComment');
          if (none) none.remove();
          var wrap = document.createElement('div');
          wrap.className = 'd-flex mb-3';
          var img = document.createElement('img');
          img.src = res.comment.avatar;
          img.className = 'fl-avatar-sm me-2';
          img.alt = '';
          var body = document.createElement('div');
          var head = document.createElement('div');
          var name = document.createElement('span');
          name.className = 'fw-semibold';
          name.textContent = res.comment.name;
          var time = document.createElement('span');
          time.className = 'text-muted small ms-1';
          time.textContent = res.comment.time;
          head.appendChild(name);
          head.appendChild(time);
          var text = document.createElement('div');
          text.textContent = res.comment.content;
          body.appendChild(head);
          body.appendChild(text);
          wrap.appendChild(img);
          wrap.appendChild(body);
          list.appendChild(wrap);
          commentForm.querySelector('textarea').value = '';
        } else {
          err.textContent = res.error || '评论失败，请重试';
          err.classList.remove('d-none');
        }
      }).catch(function () {
        btn.disabled = false;
        err.textContent = '网络异常，请重试';
        err.classList.remove('d-none');
      });
    });
  }

  // ---------- uptime ----------
  var st = document.getElementById('sitetime');
  if (st) {
    var start = parseInt(st.getAttribute('data-start'), 10) * 1000;
    function tick() {
      var diff = Math.max(0, Date.now() - start);
      var d = Math.floor(diff / 86400000);
      var h = Math.floor(diff % 86400000 / 3600000);
      var m = Math.floor(diff % 3600000 / 60000);
      var s = Math.floor(diff % 60000 / 1000);
      st.textContent = d + ' 天 ' + h + ' 小时 ' + m + ' 分 ' + s + ' 秒';
    }
    tick();
    setInterval(tick, 1000);
  }
})();
