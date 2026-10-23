(function() {
    'use strict';

    var config = window.qigeoPlatformBacktestRecordData || null;
    var form = document.getElementById('qigeo-platform-log-form');
    var generateButton = document.getElementById('qigeo-generate-platform-log-btn');
    var saveButton = document.getElementById('qigeo-save-platform-log-btn');
    var feedback = document.getElementById('qigeo-platform-log-feedback');

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
        var platformInput = byId('qigeo-platform-log-target-platform');
        var modelSelect = byId('qigeo-platform-log-model-id');
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

        var matchedOption = null;
        Array.prototype.some.call(modelSelect.options, function(option) {
            if (!option || !option.value || !option.dataset) {
                return false;
            }

            if (option.dataset.providerType === platformKey) {
                matchedOption = option;
                return true;
            }

            return false;
        });

        if (matchedOption) {
            modelSelect.value = matchedOption.value;
        }
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

    function fillResults(result, meta) {
        if (!result || typeof result !== 'object') {
            return;
        }

        var modelSelect = byId('qigeo-platform-log-model-id');
        var resolvedModelId = '';
        if (meta && typeof meta.model_id === 'string' && meta.model_id) {
            resolvedModelId = meta.model_id;
        } else if (typeof result.model_id === 'string' && result.model_id) {
            resolvedModelId = result.model_id;
        }

        if (modelSelect && resolvedModelId) {
            modelSelect.value = resolvedModelId;
        }

        var fields = {
            'qigeo-platform-log-result-summary': result.summary || '',
            'qigeo-platform-log-result-mention': result.mention_judgement || '',
            'qigeo-platform-log-result-winning': joinLines(result.winning_fragments),
            'qigeo-platform-log-result-missing': joinLines(result.missing_signals),
            'qigeo-platform-log-result-risk': joinLines(result.risk_signals),
            'qigeo-platform-log-result-assets': joinLines(result.asset_updates),
            'qigeo-platform-log-result-adaptation': joinLines(result.adaptation_updates),
            'qigeo-platform-log-result-next-questions': joinLines(result.next_questions),
            'qigeo-platform-log-result-steps': joinLines(result.execution_steps)
        };

        Object.keys(fields).forEach(function(id) {
            var element = byId(id);
            if (element) {
                element.value = fields[id];
            }
        });
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
        url.searchParams.set('page', 'qigeo-platform-backtest-records');
        url.searchParams.set('action', 'edit');
        url.searchParams.set('id', String(recordId));
        window.history.replaceState({}, '', url.toString());
    }

    generateButton.addEventListener('click', async function() {
        try {
            setBusy(true);
            syncModelWithPlatform();
            setFeedback(config.messages && config.messages.generating ? config.messages.generating : '正在分析...', false);
            var data = await postForm('qigeo_generate_platform_backtest_records', config.generateNonce);
            fillResults(data.result || {}, data);
            setFeedback(data.message || (config.messages && config.messages.generateDone) || '已生成', false);
        } catch (error) {
            setFeedback(error && error.message ? error.message : '分析失败，请稍后重试', true);
        } finally {
            setBusy(false);
        }
    });

    saveButton.addEventListener('click', async function() {
        try {
            setBusy(true);
            syncModelWithPlatform();
            setFeedback(config.messages && config.messages.saving ? config.messages.saving : '正在保存...', false);
            var data = await postForm('qigeo_save_platform_backtest_records', config.saveNonce);
            var recordIdInput = byId('qigeo-platform-log-record-id');
            if (recordIdInput && data.record_id) {
                recordIdInput.value = String(data.record_id);
            }

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

    var platformInput = byId('qigeo-platform-log-target-platform');
    if (platformInput) {
        platformInput.addEventListener('change', syncModelWithPlatform);
        platformInput.addEventListener('blur', syncModelWithPlatform);
        syncModelWithPlatform();
    }
})();
