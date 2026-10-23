(function() {
    'use strict';

    var config = window.qigeoQuestionLibraryData || null;
    var form = document.getElementById('qigeo-question-form');
    var generateButton = document.getElementById('qigeo-generate-question-btn');
    var saveButton = document.getElementById('qigeo-save-question-btn');
    var feedback = document.getElementById('qigeo-question-feedback');

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
        var platformInput = byId('qigeo-question-target-platform');
        var modelSelect = byId('qigeo-question-model-id');
        if (!platformInput || !modelSelect) {
            return;
        }

        var platformKey = normalizePlatformKey(platformInput.value);
        if (!platformKey) {
            return;
        }

        var currentOption = modelSelect.options[modelSelect.selectedIndex];
        if (currentOption && currentOption.dataset && currentOption.dataset.providerType && currentOption.dataset.providerType !== platformKey) {
            return;
        }

        var fallbackOption = null;
        Array.prototype.some.call(modelSelect.options, function(option) {
            if (!option || !option.value) {
                return false;
            }
            if (option.dataset && option.dataset.providerType && option.dataset.providerType !== platformKey) {
                modelSelect.value = option.value;
                return true;
            }
            if (!fallbackOption && option.dataset && option.dataset.providerType === platformKey) {
                fallbackOption = option;
            }
            return false;
        });

        if (fallbackOption && modelSelect.value !== fallbackOption.value && (!currentOption || !currentOption.value)) {
            modelSelect.value = fallbackOption.value;
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

        var modelSelect = byId('qigeo-question-model-id');
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
            'qigeo-question-result-summary': result.summary || '',
            'qigeo-question-result-strategy': result.strategy_summary || '',
            'qigeo-question-result-primary': joinLines(result.primary_questions),
            'qigeo-question-result-supporting': joinLines(result.supporting_questions),
            'qigeo-question-result-comparison': joinLines(result.comparison_questions),
            'qigeo-question-result-scenario': joinLines(result.scenario_questions),
            'qigeo-question-result-risk': joinLines(result.risk_questions),
            'qigeo-question-result-gaps': joinLines(result.content_gap_topics),
            'qigeo-question-result-steps': joinLines(result.execution_steps)
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
            var message = json.data && json.data.message ? json.data.message : '请求失败，请稍后重试';
            throw new Error(message);
        }

        return json.data || {};
    }

    function updateUrl(recordId) {
        if (!recordId || !window.history || !window.history.replaceState) {
            return;
        }

        var url = new URL(window.location.href);
        url.searchParams.set('page', 'qigeo-question-library');
        url.searchParams.set('action', 'edit');
        url.searchParams.set('id', String(recordId));
        window.history.replaceState({}, '', url.toString());
    }

    generateButton.addEventListener('click', async function() {
        try {
            setBusy(true);
            syncModelWithPlatform();
            setFeedback(config.messages && config.messages.generating ? config.messages.generating : '正在生成...', false);
            var data = await postForm('qigeo_generate_question_library', config.generateNonce);
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
            var data = await postForm('qigeo_save_question_library', config.saveNonce);
            var recordIdInput = byId('qigeo-question-record-id');
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

    var platformInput = byId('qigeo-question-target-platform');
    if (platformInput) {
        platformInput.addEventListener('change', syncModelWithPlatform);
        platformInput.addEventListener('blur', syncModelWithPlatform);
        syncModelWithPlatform();
    }
})();
