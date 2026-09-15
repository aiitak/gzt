/* ============================================================
   工资条系统 · 后台通用 JS
   1) 内置 weui 兼容垫片：页面 JS 仍可调 weui.toast / loading /
      confirm / alert / topTips，但不再依赖外部 weui.min.js，
      外链挂掉也不会让按钮"点了没反应"。
   2) 提供全局 doLogout()，供各页顶栏「退出」按钮调用。
   依赖：assets/admin.css 中的 .ad-toast / .ad-loading-mask /
        .ad-modal-* 样式。
   ============================================================ */
(function () {
    'use strict';

    /* ---------- Toast ---------- */
    function ensureToastEl() {
        var el = document.getElementById('__adToast');
        if (!el) {
            el = document.createElement('div');
            el.id = '__adToast';
            el.className = 'ad-toast';
            document.body.appendChild(el);
        }
        return el;
    }
    var toastTimer = null;
    function toast(msg, duration) {
        var el = ensureToastEl();
        el.textContent = msg || '';
        el.className = 'ad-toast ad-toast--ok show';
        if (toastTimer) clearTimeout(toastTimer);
        toastTimer = setTimeout(function () {
            el.className = 'ad-toast ad-toast--ok';
        }, (duration || 2000));
    }
    function topTips(msg, duration) {
        var el = ensureToastEl();
        el.textContent = msg || '';
        el.className = 'ad-toast ad-toast--err show';
        if (toastTimer) clearTimeout(toastTimer);
        toastTimer = setTimeout(function () {
            el.className = 'ad-toast ad-toast--err';
        }, (duration || 2000));
    }

    /* ---------- Loading ---------- */
    function ensureLoadingEl() {
        var el = document.getElementById('__adLoading');
        if (!el) {
            el = document.createElement('div');
            el.id = '__adLoading';
            el.className = 'ad-loading-mask';
            el.innerHTML = '<div class="ad-loading-box"><div class="ad-spinner"></div>' +
                '<div class="ad-loading-text">加载中…</div></div>';
            document.body.appendChild(el);
        }
        return el;
    }
    function loading(text) {
        var el = ensureLoadingEl();
        var t = el.querySelector('.ad-loading-text');
        if (t) t.textContent = text || '加载中…';
        el.classList.add('show');
    }
    function hideLoading() {
        var el = document.getElementById('__adLoading');
        if (el) el.classList.remove('show');
    }

    /* ---------- Modal (alert / confirm) ---------- */
    function openModal(opts) {
        var mask = document.createElement('div');
        mask.className = 'ad-modal-mask';
        var actions = '';
        if (opts.showCancel) {
            actions += '<button class="ad-modal__btn" data-act="cancel">取消</button>';
        }
        actions += '<button class="ad-modal__btn ad-modal__btn--primary" data-act="ok">' +
            (opts.okText || '确定') + '</button>';
        mask.innerHTML = '<div class="ad-modal"><div class="ad-modal__body">' +
            (opts.message || '') + '</div><div class="ad-modal__actions">' +
            actions + '</div></div>';
        document.body.appendChild(mask);
        requestAnimationFrame(function () { mask.classList.add('show'); });

        function close() {
            mask.classList.remove('show');
            setTimeout(function () { mask.remove(); }, 180);
        }
        mask.querySelector('[data-act="ok"]').addEventListener('click', function () {
            close();
            if (typeof opts.onOk === 'function') opts.onOk();
        });
        var cancelBtn = mask.querySelector('[data-act="cancel"]');
        if (cancelBtn) {
            cancelBtn.addEventListener('click', function () {
                close();
                if (typeof opts.onCancel === 'function') opts.onCancel();
            });
        }
        mask.addEventListener('click', function (e) {
            if (e.target === mask && opts.showCancel) {
                close();
                if (typeof opts.onCancel === 'function') opts.onCancel();
            }
        });
    }
    function alert(message, cb) {
        openModal({ message: message, showCancel: false, onOk: cb });
    }
    function confirm(message, onOk, onCancel) {
        openModal({ message: message, showCancel: true, onOk: onOk, onCancel: onCancel });
    }

    /* ---------- 全局 weui 垫片（仅当外部未提供真实 weui 时） ---------- */
    if (typeof window.weui === 'undefined' || !window.weui) {
        window.weui = {
            toast: toast,
            topTips: topTips,
            loading: loading,
            hideLoading: hideLoading,
            alert: alert,
            confirm: confirm
        };
    }

    /* ---------- 全局 XSS 转义 ---------- */
    window.esc = function (s) {
        return String(s == null ? '' : s).replace(/[&<>"']/g, function (c) {
            return { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c];
        });
    };

    /* ---------- 全局退出 ---------- */
    window.doLogout = function () {
        fetch('/api/admin/logout', { method: 'POST' })
            .then(function () { window.location.href = '/admin/dashboard'; })
            .catch(function () { window.location.href = '/admin/dashboard'; });
    };

    /* ---------- 金额隐藏/显示（全局开关） ----------
       用法：金额用 <span class="amt-toggle">👁<span class="amt-val">¥1234.56</span></span> 包裹
       点击任意 .amt-toggle 切换全局显示状态，localStorage 持久化。
       body 添加 .money-hide 时所有 .amt-val 隐藏、.amt-eye 显示。
    */
    window.fmtMoney = function (val, prefix) {
        prefix = prefix || '¥';
        var s = parseFloat(val || 0).toFixed(2);
        return '<span class="amt-toggle"><span class="amt-eye">👁</span><span class="amt-val">' + prefix + s + '</span></span>';
    };
    function initMoneyToggle() {
        var MONEY_VISIBLE = localStorage.getItem('money_visible') === '1';
        if (!MONEY_VISIBLE) {
            document.body.classList.add('money-hide');
        }
        document.addEventListener('click', function (e) {
            if (e.target.closest('.amt-toggle')) {
                MONEY_VISIBLE = !MONEY_VISIBLE;
                localStorage.setItem('money_visible', MONEY_VISIBLE ? '1' : '0');
                document.body.classList.toggle('money-hide', !MONEY_VISIBLE);
            }
        });
    }
    if (document.body) {
        initMoneyToggle();
    } else {
        document.addEventListener('DOMContentLoaded', initMoneyToggle);
    }

    /* ---------- 通用前端分页渲染 ----------
       用法：renderPager(containerId, currentPage, totalPages, total, pageSize, goPageFnName, changeSizeFnName)
       goPageFnName / changeSizeFnName 为全局函数名字符串，供 onclick 调用
    */
    window.renderPager = function (containerId, currentPage, totalPages, total, pageSize, goPageFnName, changeSizeFnName) {
        var el = document.getElementById(containerId);
        if (!el) return;
        if (total === 0) { el.innerHTML = ''; return; }

        if (typeof window[goPageFnName] === 'function') {
            var origFn = window[goPageFnName].__origFn || window[goPageFnName];
            window[goPageFnName] = function (p) {
                p = parseInt(p) || 1;
                p = Math.max(1, Math.min(totalPages, p));
                return origFn(p);
            };
            window[goPageFnName].__origFn = origFn;
        }

        var html = '<span>每页</span>';
        html += '<select onchange="' + changeSizeFnName + '(this.value)">';
        [10, 20, 50, 100].forEach(function (n) {
            html += '<option value="' + n + '"' + (pageSize === n ? ' selected' : '') + '>' + n + '</option>';
        });
        html += '</select>';
        html += '<span>条</span>';
        html += '<span class="pg-info">总计 ' + total + ' 条</span>';
        html += '<button class="pg-btn"' + (currentPage <= 1 ? ' disabled' : '') + ' onclick="' + goPageFnName + '(1)">|‹ 首页</button>';
        html += '<button class="pg-btn"' + (currentPage <= 1 ? ' disabled' : '') + ' onclick="' + goPageFnName + '(' + (currentPage - 1) + ')">‹ 上一页</button>';
        html += '<span>第 ' + currentPage + ' 页 / 共 ' + totalPages + ' 页</span>';
        html += '<button class="pg-btn"' + (currentPage >= totalPages ? ' disabled' : '') + ' onclick="' + goPageFnName + '(' + (currentPage + 1) + ')">下一页 ›</button>';
        html += '<button class="pg-btn"' + (currentPage >= totalPages ? ' disabled' : '') + ' onclick="' + goPageFnName + '(' + totalPages + ')">末页 ›|</button>';
        el.innerHTML = html;
    };

    /* ---------- 通用分页工厂 ----------
       用法：
         var p = createPager({
           containerId: 'pager',
           tbodyId:     'rows',
           apiUrl:      '/api/xxx/list',
           renderRow:   function(r) { return '<tr>...</tr>'; },
           searchInputId: 'qInp',         // 可选
           statusSelectId:'statusSel',    // 可选
           extraParams: { dept: 'all' },  // 可选
           statusMap:   {1:'审批中', ...}, // 可选，自动生成 badge
           pageSize:    20,               // 可选
           emptyText:   '无数据',         // 可选
         });
         p.load();    // 首次/重新加载
       拼音模式：q 为纯字母时自动拉全量 → matchPinyinQuery 前端过滤 → 本地分页
    */
    window.createPager = function(cfg) {
        var page = 1;
        // 从 localStorage 读取已保存的每页数量
        var storageKey = '_pager_' + cfg.containerId + '_size';
        var savedSize = parseInt(localStorage.getItem(storageKey));
        var pageSize = savedSize && savedSize > 0 ? savedSize : (cfg.pageSize || 20);
        var totalPages = 1;
        var allRows = null, allTotal = 0, pyCacheTs = 0, pyCacheKey = '';

        function getParams() {
            var q = cfg.searchInputId ? (document.getElementById(cfg.searchInputId).value.trim()) : '';
            var status = cfg.statusSelectId ? document.getElementById(cfg.statusSelectId).value : 'all';
            return { q: q, status: status };
        }

        function extraParamStr() {
            if (!cfg.extraParams) return '';
            var parts = [];
            for (var k in cfg.extraParams) {
                if (cfg.extraParams.hasOwnProperty(k)) {
                    parts.push(encodeURIComponent(k) + '=' + encodeURIComponent(cfg.extraParams[k]));
                }
            }
            return parts.length ? '&' + parts.join('&') : '';
        }

        function statusBadge(val) {
            if (!cfg.statusMap) return '';
            var text = cfg.statusMap[val] || ('状态' + val);
            var v = parseInt(val) || parseInt(val) === 0 ? parseInt(val) : val;
            var cls = ({1:'badge-info',2:'badge-ok',3:'badge-err',4:'badge-gray',6:'badge-warn',7:'badge-gray',10:'badge-ok'})[v] || 'badge-gray';
            return '<span class="' + cls + '">' + text + '</span>';
        }

        function renderList(rows, total) {
            var tbody = document.getElementById(cfg.tbodyId);
            if (!tbody) return;
            if (!rows.length) {
                tbody.innerHTML = '<tr><td colspan="' + (cfg.colSpan || 6) + '" style="text-align:center;color:#999;padding:20px;">' + (cfg.emptyText || '无数据') + '</td></tr>';
            } else {
                tbody.innerHTML = rows.map(cfg.renderRow).join('');
                // 事件委托：处理带 data-spno 属性的按钮点击
                tbody.querySelectorAll('button[data-spno]').forEach(function(btn) {
                    btn.addEventListener('click', function() {
                        if (typeof cfg.onDetailClick === 'function') {
                            cfg.onDetailClick(btn.getAttribute('data-spno'));
                        }
                    });
                });
            }
            totalPages = Math.ceil(total / pageSize) || 1;
            if (page > totalPages) page = totalPages;
            window.renderPager(cfg.containerId, page, totalPages, total, pageSize, '_cp_' + cfg.containerId + '_go', '_cp_' + cfg.containerId + '_cs');
        }

        window['_cp_' + cfg.containerId + '_go'] = function(p) {
            p = parseInt(p) || 1;
            p = Math.max(1, Math.min(totalPages, p));
            page = p;
            load();
        };
        window['_cp_' + cfg.containerId + '_cs'] = function(v) {
            pageSize = parseInt(v) || 20;
            page = 1;
            localStorage.setItem(storageKey, String(pageSize));
            load();
        };

        async function load() {
            var tbody = document.getElementById(cfg.tbodyId);
            if (!tbody) return;
            var span = tbody.querySelector('td') ? tbody.querySelector('td').getAttribute('colspan') : '6';
            tbody.innerHTML = '<tr><td colspan="' + (cfg.colSpan || 6) + '" style="text-align:center;color:#999;padding:20px;">加载中...</td></tr>';

            var p = getParams();
            var isPinyin = p.q !== '' && /^[a-zA-Z]+$/.test(p.q);

            try {
                if (isPinyin) {
                    if (!allRows || !pyCacheKey || pyCacheKey !== p.status || (Date.now() - pyCacheTs) > 60000) {
                        var url = cfg.apiUrl + '?status=' + encodeURIComponent(p.status) + '&all=1' + extraParamStr();
                        var r = await fetch(url);
                        var d = await r.json();
                        if (!d.success) {
                            tbody.innerHTML = '<tr><td colspan="' + (cfg.colSpan || 6) + '" style="text-align:center;color:#fa5151;padding:20px;">' + esc(d.error || '加载失败') + '</td></tr>';
                            return;
                        }
                        allRows = d.data || [];
                        allTotal = d.total || 0;
                        pyCacheKey = p.status;
                        pyCacheTs = Date.now();
                    }
                    var qLow = p.q.toLowerCase();
                    var filtered = allRows.filter(function(x) {
                        if (typeof matchPinyinQuery === 'function' && matchPinyinQuery(p.q, x.name || '')) return true;
                        if ((x.userid || '').toLowerCase().indexOf(qLow) >= 0) return true;
                        if ((x.sp_no || '').toLowerCase().indexOf(qLow) >= 0) return true;
                        return false;
                    });
                    var start = (page - 1) * pageSize;
                    renderList(filtered.slice(start, start + pageSize), filtered.length);
                } else {
                    allRows = null; pyCacheTs = 0; pyCacheKey = '';
                    var url2 = cfg.apiUrl + '?page=' + page + '&pageSize=' + pageSize
                        + '&status=' + encodeURIComponent(p.status) + '&q=' + encodeURIComponent(p.q) + extraParamStr();
                    var r2 = await fetch(url2);
                    var d2 = await r2.json();
                    if (!d2.success) {
                        tbody.innerHTML = '<tr><td colspan="' + (cfg.colSpan || 6) + '" style="text-align:center;color:#fa5151;padding:20px;">' + esc(d2.error || '加载失败') + '</td></tr>';
                        return;
                    }
                    renderList(d2.data || [], d2.total || 0);
                }
            } catch (e) {
                tbody.innerHTML = '<tr><td colspan="' + (cfg.colSpan || 6) + '" style="text-align:center;color:#fa5151;padding:20px;">请求异常：' + esc(e.message) + '</td></tr>';
            }
        }

        function reset() {
            if (cfg.searchInputId) document.getElementById(cfg.searchInputId).value = '';
            if (cfg.statusSelectId) document.getElementById(cfg.statusSelectId).value = 'all';
            allRows = null; pyCacheTs = 0; pyCacheKey = '';
            page = 1;
            load();
        }

        // 绑定搜索框 Enter 键
        if (cfg.searchInputId) {
            var inp = document.getElementById(cfg.searchInputId);
            if (inp) inp.addEventListener('keydown', function(e) { if (e.key === 'Enter') load(); });
        }

        return {
            load: load,
            reset: reset,
            loadStatusBadge: statusBadge,
            getPage: function() { return page; },
            getPageSize: function() { return pageSize; },
        };
    };
})();
