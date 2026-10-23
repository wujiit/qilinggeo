(function() {
    'use strict';

    var config = window.qigeoBacktestCenterData || null;
    var form = document.getElementById('qigeo-backtest-form');
    var generateButton = document.getElementById('qigeo-generate-backtest-btn');
    var saveButton = document.getElementById('qigeo-save-backtest-btn');
    var feedback = document.getElementById('qigeo-backtest-feedback');

    if (!config || !form || !generateButton || !saveButton || !feedback) {
        return;
    }

    function byId(id) {
        return document.getElementById(id);
    }

    function normalizePlatformKey(value) {
        if (window.QiGeoAdmin && typeof window.QiGeoAdmin.normalizePlatformKey === 'function') {
            return window.QiGeoAdmin.normalizePlatformKey(value);
        }
        var text = typeof value === 'string' ? value.toLowerCase().trim() : '';
        if (!text) {
            return '';
        }

        var aliases = {
            doubao: ['doubao', '豆包', '火山', '方舟', 'ark', 'volcengine'],
            deepseek: ['deepseek', 'deep seek', '深度求索', '深度搜索', '深度思考'],
            openai: ['openai', 'open ai', 'chatgpt', 'chat gpt', 'gpt'],
            qwen: ['qwen', '通义', '千问', '阿里云百炼', '百炼', 'dashscope']
        };

        return Object.keys(aliases).find(function(key) {
            return aliases[key].some(function(alias) {
                return text.indexOf(String(alias).toLowerCase()) !== -1;
            });
        }) || '';
    }

    function syncModelWithPlatform() {
        var platformInput = byId('qigeo-backtest-target-platform');
        var modelSelect = byId('qigeo-backtest-model-id');
        if (window.QiGeoAdmin && typeof window.QiGeoAdmin.syncModelWithPlatform === 'function') {
            window.QiGeoAdmin.syncModelWithPlatform(platformInput, modelSelect);
            return;
        }
        if (!platformInput || !modelSelect) {
            return;
        }

        var platformKey = normalizePlatformKey(platformInput.value);
        if (!platformKey) {
            return;
        }

        var currentOption = modelSelect.options[modelSelect.selectedIndex];
        if (currentOption && currentOption.dataset && currentOption.dataset.providerType === platformKey) {
            return;
        }

        Array.prototype.some.call(modelSelect.options, function(option) {
            if (!option || !option.value) {
                return false;
            }
            if (option.dataset && option.dataset.providerType === platformKey) {
                modelSelect.value = option.value;
                return true;
            }
            return false;
        });
    }

    function setFeedback(message, isError) {
        feedback.textContent = message || '';
        feedback.classList.toggle('is-error', !!isError);
        feedback.classList.toggle('is-success', !!message && !isError);
    }

    function setBusy(isBusy) {
        generateButton.disabled = isBusy;
        saveButton.disabled = isBusy;
    }

    function joinLines(items) {
        if (!Array.isArray(items)) {
            return '';
        }
        return items.filter(function(item) {
            return typeof item === 'string' && item.trim() !== '';
        }).join('\n');
    }

    function setValue(id, value) {
        var element = byId(id);
        if (element) {
            element.value = value == null ? '' : String(value);
        }
    }

    function fillResults(result, meta) {
        if (!result || typeof result !== 'object') {
            return;
        }

        if (meta && typeof meta.model_id === 'string' && meta.model_id) {
            setValue('qigeo-backtest-model-id', meta.model_id);
        }

        setValue('qigeo-backtest-result-summary', result.summary || '');
        setValue('qigeo-backtest-result-simulation', result.simulation_notice || '');
        setValue('qigeo-backtest-result-assessment', result.overall_assessment || '');
        setValue('qigeo-backtest-result-rate', result.hit_rate_label || '');
        setValue('qigeo-backtest-result-scorecard', result.scorecard || '');
        setValue('qigeo-backtest-result-hit', joinLines(result.hit_questions));
        setValue('qigeo-backtest-result-assist-list', joinLines(result.assist_questions));
        setValue('qigeo-backtest-result-missed', joinLines(result.missed_questions));
        setValue('qigeo-backtest-result-risk', joinLines(result.risky_questions));
        setValue('qigeo-backtest-result-patterns', joinLines(result.response_patterns));
        setValue('qigeo-backtest-result-actions', joinLines(result.next_actions));

        var counts = result.counts || {};
        setValue('qigeo-backtest-hit-rate', result.hit_rate || '');
        setValue('qigeo-backtest-total-questions', counts.total_questions || '');
        setValue('qigeo-backtest-hit-count', counts.hit_count || '');
        setValue('qigeo-backtest-assist-count', counts.assist_count || '');
        setValue('qigeo-backtest-miss-count', counts.miss_count || '');
        setValue('qigeo-backtest-risk-count', counts.risk_count || '');
    }

    async function postForm(action, nonce) {
        var body = new FormData(form);
        body.append('action', action);
        body.append('nonce', nonce);

        var response = await fetch(config.ajaxUrl, {
            method: 'POST',
            body: body,
            credentials: 'same-origin'
        });

        var json = await response.json();
        if (!json || typeof json !== 'object') {
            throw new Error('服务器返回异常，请稍后重试');
        }
        if (!json.success) {
            throw new Error(json.data && json.data.message ? json.data.message : '请求失败，请稍后重试');
        }
        return json.data || {};
    }

    function updateUrl(recordId) {
        if (!recordId || !window.history || !window.history.replaceState) {
            return;
        }
        var url = new URL(window.location.href);
        url.searchParams.set('page', 'qigeo-backtest-center');
        url.searchParams.set('action', 'edit');
        url.searchParams.set('id', String(recordId));
        window.history.replaceState({}, '', url.toString());
    }

    generateButton.addEventListener('click', async function() {
        try {
            setBusy(true);
            syncModelWithPlatform();
            setFeedback(config.messages && config.messages.generating ? config.messages.generating : '正在生成...', false);
            var data = await postForm('qigeo_generate_backtest_center', config.generateNonce);
            fillResults(data.result || {}, data);
            setFeedback(data.message || (config.messages && config.messages.generateDone) || '已生成', false);
        } catch (error) {
            setFeedback(error && error.message ? error.message : '生成失败，请稍后重试', true);
        } finally {
            setBusy(false);
        }
    });

    saveButton.addEventListener('click', async function() {
        try {
            setBusy(true);
            syncModelWithPlatform();
            setFeedback(config.messages && config.messages.saving ? config.messages.saving : '正在保存...', false);
            var data = await postForm('qigeo_save_backtest_center', config.saveNonce);
            setValue('qigeo-backtest-record-id', data.record_id || '');
            if (data.record_id) {
                updateUrl(data.record_id);
            }
            setFeedback(data.message || (config.messages && config.messages.saveDone) || '已保存', false);
        } catch (error) {
            setFeedback(error && error.message ? error.message : '保存失败，请稍后重试', true);
        } finally {
            setBusy(false);
        }
    });

    var platformInput = byId('qigeo-backtest-target-platform');
    if (platformInput) {
        platformInput.addEventListener('change', syncModelWithPlatform);
        platformInput.addEventListener('blur', syncModelWithPlatform);
        syncModelWithPlatform();
    }
})();
