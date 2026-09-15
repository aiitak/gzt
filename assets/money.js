/* 金额隐藏/显示（全局开关）
   用法：金额用 <span class="amt-toggle"><span class="amt-eye">👁</span><span class="amt-val">¥1234.56</span></span> 包裹
   或调用 fmtMoney(val) 生成上述 HTML。
   点击任意 .amt-toggle 切换全局显示状态，localStorage 持久化。
*/
(function () {
    'use strict';
    // 注入样式
    var style = document.createElement('style');
    style.textContent =
        '.amt-toggle{cursor:pointer;user-select:none;display:inline-flex;align-items:center;gap:2px;}'
        + '.amt-eye{display:none;font-size:0.9em;opacity:0.5;}'
        + '.money-hide .amt-eye{display:inline;}'
        + '.money-hide .amt-val{display:none!important;}';
    document.head.appendChild(style);

    var MONEY_VISIBLE = localStorage.getItem('money_visible') === '1';
    if (!MONEY_VISIBLE) document.body.classList.add('money-hide');

    document.addEventListener('click', function (e) {
        if (e.target.closest('.amt-toggle')) {
            MONEY_VISIBLE = !MONEY_VISIBLE;
            localStorage.setItem('money_visible', MONEY_VISIBLE ? '1' : '0');
            document.body.classList.toggle('money-hide', !MONEY_VISIBLE);
        }
    });

    window.fmtMoney = function (val, prefix) {
        prefix = prefix || '¥';
        var s = parseFloat(val || 0).toFixed(2);
        return '<span class="amt-toggle"><span class="amt-eye">👁</span><span class="amt-val">' + prefix + s + '</span></span>';
    };
})();
