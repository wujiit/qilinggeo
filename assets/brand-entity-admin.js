(function() {
    'use strict';

    var config = window.qigeoBrandEntityLibraryData || null;
    var form = document.getElementById('qigeo-brand-form');
    var generateButton = document.getElementById('qigeo-generate-brand-btn');
    var saveButton = document.getElementById('qigeo-save-brand-btn');
    var feedback = document.getElementById('qigeo-brand-feedback');

    if (!config || !form || !generateButton || !saveButton || !feedback) {
        return;
    }

    function byId(id) {
        return document.getElementById(id);
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

        var modelSelect = byId('qigeo-brand-model-id');
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
            'qigeo-brand-result-summary': result.summary || '',
            'qigeo-brand-result-definition': result.entity_definition || '',
            'qigeo-brand-result-facts': joinLines(result.canonical_facts),
            'qigeo-brand-result-naming': joinLines(result.naming_rules),
            'qigeo-brand-result-positioning': joinLines(result.comparison_positioning),
            'qigeo-brand-result-faq': joinLines(result.faq_facts),
            'qigeo-brand-result-citations': joinLines(result.citation_sources),
            'qigeo-brand-result-forbidden': joinLines(result.forbidden_claims),
            'qigeo-brand-result-schema': joinLines(result.schema_plan),
            'qigeo-brand-result-steps': joinLines(result.execution_steps)
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
        url.searchParams.set('page', 'qigeo-brand-entity-library');
        url.searchParams.set('action', 'edit');
        url.searchParams.set('id', String(recordId));
        window.history.replaceState({}, '', url.toString());
    }

    generateButton.addEventListener('click', async function() {
        try {
            setBusy(true);
            setFeedback(config.messages && config.messages.generating ? config.messages.generating : '正在生成...', false);
            var data = await postForm('qigeo_generate_brand_entity_library', config.generateNonce);
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
            setFeedback(config.messages && config.messages.saving ? config.messages.saving : '正在保存...', false);
            var data = await postForm('qigeo_save_brand_entity_library', config.saveNonce);
            var recordIdInput = byId('qigeo-brand-record-id');
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
})();
