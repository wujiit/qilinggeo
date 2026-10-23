(function() {
    'use strict';

    function request(url, body) {
        return fetch(url, {
            method: 'POST',
            credentials: 'same-origin',
            headers: {
                'Content-Type': 'application/x-www-form-urlencoded; charset=UTF-8'
            },
            body: body.toString()
        }).then(function(res) {
            return res.json();
        });
    }

    function appendSourceUrl(body, box) {
        var input = box ? box.querySelector('.qigeo-source-url-input') : null;
        if (!input) {
            input = document.querySelector('.qigeo-source-url-input');
        }
        if (input && input.value) {
            body.append('source_url', input.value.trim());
        }
    }

    function appendFaqDraft(body, box) {
        var fields = readFaqFields(box);
        if (!fields) {
            return;
        }

        if (fields.summaryEl) {
            body.append('summary', fields.summaryEl.value || '');
        }
        if (fields.keyPointsEl) {
            body.append('key_points_lines', fields.keyPointsEl.value || '');
        }
        if (fields.faqLinesEl) {
            body.append('faq_lines', fields.faqLinesEl.value || '');
        }
        if (fields.suggestionsEl) {
            body.append('suggestions_lines', fields.suggestionsEl.value || '');
        }
        if (fields.seoTitleEl) {
            body.append('seo_title', fields.seoTitleEl.value || '');
        }
        if (fields.seoKeywordsEl) {
            body.append('seo_keywords', fields.seoKeywordsEl.value || '');
        }
        if (fields.seoDescriptionEl) {
            body.append('seo_description', fields.seoDescriptionEl.value || '');
        }
    }

    function getGeoActionConfig() {
        return window.qigeoPostAdmin || window.qigeoFaqLibraryData || null;
    }

    function appendSecondaryTargets(body, box) {
        var checkboxes = box ? box.querySelectorAll('.qigeo-secondary-target-model') : [];
        if (!checkboxes || checkboxes.length === 0) {
            return true;
        }

        var selected = 0;
        checkboxes.forEach(function(input) {
            if (input.checked && input.value) {
                body.append('secondary_model_ids[]', input.value);
                selected++;
            }
        });

        return selected > 0;
    }

    function appendFeedTargets(body, box) {
        var checkboxes = box ? box.querySelectorAll('.qigeo-feed-target-model') : [];
        if (!checkboxes || checkboxes.length === 0) {
            return true;
        }

        var selected = 0;
        checkboxes.forEach(function(input) {
            if (input.checked && input.value) {
                body.append('feed_model_ids[]', input.value);
                selected++;
            }
        });

        return selected > 0;
    }

    function runAnalysis(button) {
        var postId = button.getAttribute('data-post-id');
        if (!postId || !window.qigeoPostAdmin) {
            return;
        }

        var box = button.closest('.qigeo-faq-library-box') || button.closest('.qigeo-meta-box');
        var messageEl = box ? box.querySelector('.qigeo-run-analysis-message') : null;
        button.disabled = true;
        button.textContent = qigeoPostAdmin.runningText || '分析中...';

        var body = new URLSearchParams();
        body.append('action', 'qigeo_run_analysis');
        body.append('nonce', qigeoPostAdmin.nonce || '');
        body.append('post_id', postId);
        appendSourceUrl(body, box);

        request(qigeoPostAdmin.ajaxUrl, body)
            .then(function(data) {
                if (data && data.success) {
                    if (messageEl) {
                        messageEl.style.color = '#059669';
                        var note = data.data && data.data.message ? data.data.message : '已加入 GEO 分析队列';
                        messageEl.innerHTML = note + ' <a href="#" class="qigeo-reload-hint" style="margin-left:6px;color:#0284c7;text-decoration:underline;" onclick="window.location.reload();return false;">刷新面板</a>';
                    }
                    return;
                }
                if (messageEl) {
                    messageEl.style.color = '#b32d2e';
                    messageEl.textContent = data && data.data && data.data.message ? data.data.message : '分析失败';
                }
            })
            .catch(function() {
                if (messageEl) {
                    messageEl.style.color = '#b32d2e';
                    messageEl.textContent = '网络错误，请稍后重试';
                }
            })
            .finally(function() {
                button.disabled = false;
                button.textContent = qigeoPostAdmin.idleText || '开始 GEO 分析';
            });
    }

    function runSecondaryReview(button) {
        var postId = button.getAttribute('data-post-id');
        var config = getGeoActionConfig();
        if (!postId || !config) {
            return;
        }

        var box = button.closest('.qigeo-faq-library-box') || button.closest('.qigeo-meta-box');
        var messageEl = box ? box.querySelector('.qigeo-secondary-review-message') : null;
        button.disabled = true;
        button.textContent = config.secondaryRunningText || '分析中...';
        if (messageEl) {
            messageEl.style.color = '';
            messageEl.textContent = '';
        }

        var body = new URLSearchParams();
        body.append('action', 'qigeo_run_secondary_review');
        body.append('nonce', config.secondaryNonce || '');
        body.append('post_id', postId);
        appendSourceUrl(body, box);
        appendFaqDraft(body, box);
        if (!appendSecondaryTargets(body, box)) {
            if (messageEl) {
                messageEl.style.color = '#b32d2e';
                messageEl.textContent = '请先选择至少一个分析平台/模型';
            }
            button.disabled = false;
            button.textContent = config.secondaryIdleText || '开始多模型分析';
            return;
        }

        request(config.ajaxUrl, body)
            .then(function(data) {
                if (data && data.success) {
                    if (messageEl) {
                        messageEl.style.color = '#059669';
                        var note = data.data && data.data.message ? data.data.message : '多模型分析完成';
                        messageEl.innerHTML = note + ' <a href="#" class="qigeo-reload-hint" style="margin-left:6px;color:#0284c7;text-decoration:underline;" onclick="window.location.reload();return false;">刷新查看</a>';
                    }
                    return;
                }
                if (messageEl) {
                    messageEl.style.color = '#b32d2e';
                    messageEl.textContent = data && data.data && data.data.message ? data.data.message : '多模型分析失败';
                }
            })
            .catch(function() {
                if (messageEl) {
                    messageEl.style.color = '#b32d2e';
                    messageEl.textContent = '网络错误，请稍后重试';
                }
            })
            .finally(function() {
                button.disabled = false;
                button.textContent = config.secondaryIdleText || '开始多模型分析';
            });
    }

    function runAiFeed(button) {
        var postId = button.getAttribute('data-post-id');
        var config = getGeoActionConfig();
        if (!postId || !config) {
            return;
        }

        var box = button.closest('.qigeo-faq-library-box') || button.closest('.qigeo-meta-box');
        var messageEl = box ? box.querySelector('.qigeo-ai-feed-message') : null;
        button.disabled = true;
        button.textContent = config.aiFeedRunningText || '投喂中...';
        if (messageEl) {
            messageEl.style.color = '';
            messageEl.textContent = '';
        }

        var body = new URLSearchParams();
        body.append('action', 'qigeo_run_ai_feed');
        body.append('nonce', config.aiFeedNonce || '');
        body.append('post_id', postId);
        appendSourceUrl(body, box);
        appendFaqDraft(body, box);
        if (!appendFeedTargets(body, box)) {
            if (messageEl) {
                messageEl.style.color = '#b32d2e';
                messageEl.textContent = '请先选择至少一个投喂平台/模型';
            }
            button.disabled = false;
            button.textContent = config.aiFeedIdleText || '推送指定AI';
            return;
        }

        request(config.ajaxUrl, body)
            .then(function(data) {
                if (data && data.success) {
                    if (messageEl) {
                        messageEl.style.color = '#059669';
                        var note = data.data && data.data.message ? data.data.message : 'AI 投喂完成';
                        messageEl.innerHTML = note + ' <a href="#" class="qigeo-reload-hint" style="margin-left:6px;color:#0284c7;text-decoration:underline;" onclick="window.location.reload();return false;">刷新查看</a>';
                    }
                    return;
                }
                if (messageEl) {
                    messageEl.style.color = '#b32d2e';
                    messageEl.textContent = data && data.data && data.data.message ? data.data.message : 'AI 投喂失败';
                }
            })
            .catch(function() {
                if (messageEl) {
                    messageEl.style.color = '#b32d2e';
                    messageEl.textContent = '网络错误，请稍后重试';
                }
            })
            .finally(function() {
                button.disabled = false;
                button.textContent = config.aiFeedIdleText || '推送指定AI';
            });
    }

    function copyGeoPackage(button) {
        var postId = button.getAttribute('data-post-id');
        var config = getGeoActionConfig();
        if (!postId || !config) {
            return;
        }

        var box = button.closest('.qigeo-faq-library-box') || button.closest('.qigeo-meta-box');
        var messageEl = box ? box.querySelector('.qigeo-copy-package-message') : null;
        button.disabled = true;
        button.textContent = config.copyingText || '整理中...';
        if (messageEl) {
            messageEl.style.color = '';
            messageEl.textContent = '';
        }

        var body = new URLSearchParams();
        body.append('action', 'qigeo_get_geo_package');
        body.append('nonce', config.packageNonce || '');
        body.append('post_id', postId);
        appendSourceUrl(body, box);
        appendFaqDraft(body, box);

        request(config.ajaxUrl, body)
            .then(function(data) {
                if (!(data && data.success && data.data && data.data.copy_text)) {
                    if (messageEl) {
                        messageEl.style.color = '#b32d2e';
                        messageEl.textContent = data && data.data && data.data.message ? data.data.message : '整理失败';
                    }
                    return;
                }

                copyText(data.data.copy_text).then(function(ok) {
                    if (messageEl) {
                        messageEl.style.color = ok ? '#1d7d3d' : '#b32d2e';
                        messageEl.textContent = ok ? (data.data.message || '人工投喂数据已复制') : '复制失败，请手动复制';
                    }
                });
            })
            .catch(function() {
                if (messageEl) {
                    messageEl.style.color = '#b32d2e';
                    messageEl.textContent = '网络错误，请稍后重试';
                }
            })
            .finally(function() {
                button.disabled = false;
                button.textContent = config.copyPackageText || '复制人工投喂数据';
            });
    }

    function getFaqBoxByButton(button) {
        return button.closest('.qigeo-faq-library-box');
    }

    function readFaqFields(box) {
        if (!box) {
            return null;
        }

        return {
            recordIdEl: box.querySelector('.qigeo-faq-record-id'),
            modelEl: box.querySelector('.qigeo-faq-model-id'),
            sourceContentEl: box.querySelector('.qigeo-faq-source-content'),
            summaryEl: box.querySelector('.qigeo-faq-summary'),
            keyPointsEl: box.querySelector('.qigeo-faq-key-points'),
            sourceUrlEl: box.querySelector('.qigeo-faq-source-url'),
            faqLinesEl: box.querySelector('.qigeo-faq-lines'),
            suggestionsEl: box.querySelector('.qigeo-faq-suggestions'),
            seoTitleEl: box.querySelector('.qigeo-faq-seo-title'),
            seoKeywordsEl: box.querySelector('.qigeo-faq-seo-keywords'),
            seoDescriptionEl: box.querySelector('.qigeo-faq-seo-description'),
            useAiSeoEl: box.querySelector('.qigeo-faq-use-ai-seo'),
            generateMessageEl: box.querySelector('.qigeo-faq-generate-message'),
            saveMessageEl: box.querySelector('.qigeo-faq-save-message')
        };
    }

    function runFaqGenerate(button) {
        var postId = button.getAttribute('data-post-id');
        if (!postId || !window.qigeoFaqLibraryData) {
            return;
        }

        var box = getFaqBoxByButton(button);
        var fields = readFaqFields(box);
        if (!fields) {
            return;
        }

        var body = new URLSearchParams();
        body.append('action', 'qigeo_generate_faq_library');
        body.append('nonce', qigeoFaqLibraryData.generateNonce || '');
        body.append('post_id', postId);
        body.append('model_id', fields.modelEl ? fields.modelEl.value : '');

        button.disabled = true;
        button.textContent = qigeoFaqLibraryData.generatingText || 'AI 生成中...';
        if (fields.generateMessageEl) {
            fields.generateMessageEl.style.color = '';
            fields.generateMessageEl.textContent = '';
        }

        request(qigeoFaqLibraryData.ajaxUrl, body)
            .then(function(data) {
                if (data && data.success && data.data) {
                    if (fields.summaryEl) {
                        fields.summaryEl.value = data.data.summary || '';
                    }
                    if (fields.faqLinesEl) {
                        fields.faqLinesEl.value = data.data.faq_lines || '';
                    }
                    if (fields.keyPointsEl) {
                        fields.keyPointsEl.value = data.data.key_points_lines || '';
                    }
                    if (fields.suggestionsEl) {
                        fields.suggestionsEl.value = data.data.suggestions_lines || '';
                    }
                    if (fields.seoTitleEl) {
                        fields.seoTitleEl.value = data.data.seo_title || '';
                    }
                    if (fields.seoKeywordsEl) {
                        fields.seoKeywordsEl.value = data.data.seo_keywords || '';
                    }
                    if (fields.seoDescriptionEl) {
                        fields.seoDescriptionEl.value = data.data.seo_description || '';
                    }
                    if (fields.sourceContentEl && data.data.source_content) {
                        fields.sourceContentEl.value = data.data.source_content;
                    }
                    if (fields.sourceUrlEl && data.data.source_url) {
                        fields.sourceUrlEl.value = data.data.source_url;
                    }
                    if (fields.generateMessageEl) {
                        fields.generateMessageEl.style.color = '#1d7d3d';
                        fields.generateMessageEl.textContent = data.data.message || 'AI 已完成整理';
                    }
                    return;
                }

                if (fields.generateMessageEl) {
                    fields.generateMessageEl.style.color = '#b32d2e';
                    fields.generateMessageEl.textContent = data && data.data && data.data.message ? data.data.message : 'AI 生成失败';
                }
            })
            .catch(function() {
                if (fields.generateMessageEl) {
                    fields.generateMessageEl.style.color = '#b32d2e';
                    fields.generateMessageEl.textContent = '网络错误，请稍后重试';
                }
            })
            .finally(function() {
                button.disabled = false;
                button.textContent = qigeoFaqLibraryData.generateText || '生成 FAQ/摘要';
            });
    }

    function runFaqSave(button) {
        var postId = button.getAttribute('data-post-id');
        if (!postId || !window.qigeoFaqLibraryData) {
            return;
        }

        var box = getFaqBoxByButton(button);
        var fields = readFaqFields(box);
        if (!fields) {
            return;
        }

        var body = new URLSearchParams();
        body.append('action', 'qigeo_save_faq_library');
        body.append('nonce', qigeoFaqLibraryData.saveNonce || '');
        body.append('post_id', postId);
        body.append('record_id', fields.recordIdEl ? fields.recordIdEl.value : '');
        body.append('model_id', fields.modelEl ? fields.modelEl.value : '');
        body.append('summary', fields.summaryEl ? fields.summaryEl.value : '');
        body.append('key_points_lines', fields.keyPointsEl ? fields.keyPointsEl.value : '');
        body.append('faq_lines', fields.faqLinesEl ? fields.faqLinesEl.value : '');
        body.append('suggestions_lines', fields.suggestionsEl ? fields.suggestionsEl.value : '');
        body.append('seo_title', fields.seoTitleEl ? fields.seoTitleEl.value : '');
        body.append('seo_keywords', fields.seoKeywordsEl ? fields.seoKeywordsEl.value : '');
        body.append('seo_description', fields.seoDescriptionEl ? fields.seoDescriptionEl.value : '');
        body.append('use_ai_seo', fields.useAiSeoEl && fields.useAiSeoEl.checked ? '1' : '0');

        button.disabled = true;
        button.textContent = qigeoFaqLibraryData.savingText || '保存中...';
        if (fields.saveMessageEl) {
            fields.saveMessageEl.style.color = '';
            fields.saveMessageEl.textContent = '';
        }

        request(qigeoFaqLibraryData.ajaxUrl, body)
            .then(function(data) {
                if (data && data.success && data.data) {
                    if (fields.recordIdEl && data.data.record_id) {
                        fields.recordIdEl.value = String(data.data.record_id);
                    }
                    if (fields.saveMessageEl) {
                        fields.saveMessageEl.style.color = '#1d7d3d';
                        fields.saveMessageEl.textContent = data.data.message || '保存成功';
                    }
                    return;
                }

                if (fields.saveMessageEl) {
                    fields.saveMessageEl.style.color = '#b32d2e';
                    fields.saveMessageEl.textContent = data && data.data && data.data.message ? data.data.message : '保存失败';
                }
            })
            .catch(function() {
                if (fields.saveMessageEl) {
                    fields.saveMessageEl.style.color = '#b32d2e';
                    fields.saveMessageEl.textContent = '网络错误，请稍后重试';
                }
            })
            .finally(function() {
                button.disabled = false;
                button.textContent = qigeoFaqLibraryData.saveText || '保存到 FAQ 库';
            });
    }

    function copyText(text) {
        var value = typeof text === 'string' ? text : '';
        if (value === '') {
            return Promise.resolve(false);
        }

        if (navigator.clipboard && typeof navigator.clipboard.writeText === 'function') {
            return navigator.clipboard.writeText(value).then(function() {
                return true;
            }).catch(function() {
                return fallbackCopyText(value);
            });
        }

        return Promise.resolve(fallbackCopyText(value));
    }

    function fallbackCopyText(text) {
        var textarea = document.createElement('textarea');
        textarea.value = text;
        textarea.setAttribute('readonly', 'readonly');
        textarea.style.position = 'fixed';
        textarea.style.top = '-1000px';
        textarea.style.left = '-1000px';
        document.body.appendChild(textarea);
        textarea.select();
        textarea.setSelectionRange(0, textarea.value.length);
        var ok = false;
        try {
            ok = document.execCommand('copy');
        } catch (err) {
            ok = false;
        }
        document.body.removeChild(textarea);
        return ok;
    }

    function handleCopySeoButton(button) {
        var box = getFaqBoxByButton(button);
        if (!box) {
            return;
        }
        var targetSelector = button.getAttribute('data-target');
        if (!targetSelector) {
            return;
        }
        var target = box.querySelector(targetSelector);
        if (!target) {
            return;
        }
        var value = target.value ? String(target.value).trim() : '';
        if (value === '') {
            return;
        }

        var oldText = button.textContent;
        button.disabled = true;
        copyText(value).then(function(ok) {
            button.textContent = ok ? '已复制' : '复制失败';
            setTimeout(function() {
                button.textContent = oldText || '复制';
                button.disabled = false;
            }, 900);
        });
    }

    function handleGenerateOffpagePack(button) {
        var box = button.closest('.qigeo-offpage-pack-wrap') || document;
        var postId = button.getAttribute('data-post-id');
        var config = getGeoActionConfig();
        if (!config || !config.ajaxUrl || !config.offpageNonce || !postId) {
            return;
        }

        var messageEl = box.querySelector('.qigeo-offpage-message');
        var spinnerEl = box.querySelector('.qigeo-offpage-spinner');
        var contentBox = box.querySelector('.qigeo-offpage-content-box');
        var zhihuField = box.querySelector('#qigeo-offpage-zhihu-field');
        var xhsField = box.querySelector('#qigeo-offpage-xhs-field');
        var newsField = box.querySelector('#qigeo-offpage-news-field');

        var oldText = button.textContent;
        button.disabled = true;
        button.textContent = config.offpageRunningText || 'AI 正在生成全网引证包...';
        if (spinnerEl) spinnerEl.classList.add('is-active');
        if (messageEl) {
            messageEl.textContent = '正在根据文章论据与品牌实体改写知乎、小红书与快讯通稿...';
            messageEl.style.color = '#2271b1';
        }

        var body = new URLSearchParams();
        body.append('action', 'qigeo_generate_offpage_pack');
        body.append('nonce', config.offpageNonce);
        body.append('post_id', postId);

        request(config.ajaxUrl, body)
            .then(function(data) {
                if (spinnerEl) spinnerEl.classList.remove('is-active');
                button.disabled = false;
                button.textContent = oldText || '一键生成全网引证包 (知乎 / 小红书 / 资讯)';

                if (data && data.success && data.data) {
                    if (zhihuField) zhihuField.value = data.data.zhihu_text || '';
                    if (xhsField) xhsField.value = data.data.xhs_text || '';
                    if (newsField) newsField.value = data.data.news_text || '';
                    if (contentBox) contentBox.style.display = 'block';
                    if (messageEl) {
                        messageEl.textContent = data.data.message || '生成完成！可点击下方按钮一键复制。';
                        messageEl.style.color = '#008a20';
                    }
                } else {
                    var err = (data && data.data && data.data.message) ? data.data.message : '生成失败，请重试';
                    if (messageEl) {
                        messageEl.textContent = err;
                        messageEl.style.color = '#d63638';
                    }
                }
            })
            .catch(function(e) {
                if (spinnerEl) spinnerEl.classList.remove('is-active');
                button.disabled = false;
                button.textContent = oldText || '一键生成全网引证包 (知乎 / 小红书 / 资讯)';
                if (messageEl) {
                    messageEl.textContent = '网络请求异常：' + (e.message || '未知错误');
                    messageEl.style.color = '#d63638';
                }
            });
    }

    function handleCopyOffpageButton(button) {
        var targetSelector = button.getAttribute('data-target');
        var targetEl = document.querySelector(targetSelector);
        if (!targetEl || !targetEl.value) {
            return;
        }

        var oldText = button.textContent;
        button.disabled = true;
        copyText(targetEl.value).then(function(ok) {
            button.textContent = ok ? '已复制！可直接发帖' : '复制失败';
            setTimeout(function() {
                button.textContent = oldText || '一键复制';
                button.disabled = false;
            }, 1200);
        });
    }

    document.addEventListener('click', function(e) {
        var copyBtn = e.target.closest('.qigeo-copy-seo-btn');
        if (copyBtn) {
            e.preventDefault();
            handleCopySeoButton(copyBtn);
            return;
        }

        var offpageGenBtn = e.target.closest('.qigeo-generate-offpage-btn');
        if (offpageGenBtn) {
            e.preventDefault();
            handleGenerateOffpagePack(offpageGenBtn);
            return;
        }

        var offpageCopyBtn = e.target.closest('.qigeo-copy-offpage-btn');
        if (offpageCopyBtn) {
            e.preventDefault();
            handleCopyOffpageButton(offpageCopyBtn);
            return;
        }

        var generateBtn = e.target.closest('.qigeo-faq-generate-btn');
        if (generateBtn) {
            e.preventDefault();
            runFaqGenerate(generateBtn);
            return;
        }

        var saveBtn = e.target.closest('.qigeo-faq-save-btn');
        if (saveBtn) {
            e.preventDefault();
            runFaqSave(saveBtn);
            return;
        }

        var analysisBtn = e.target.closest('.qigeo-run-analysis-btn');
        if (analysisBtn) {
            e.preventDefault();
            runAnalysis(analysisBtn);
            return;
        }

        var secondaryBtn = e.target.closest('.qigeo-secondary-review-btn');
        if (secondaryBtn) {
            e.preventDefault();
            runSecondaryReview(secondaryBtn);
            return;
        }

        var aiFeedBtn = e.target.closest('.qigeo-ai-feed-btn');
        if (aiFeedBtn) {
            e.preventDefault();
            runAiFeed(aiFeedBtn);
            return;
        }

        var copyPackageBtn = e.target.closest('.qigeo-copy-package-btn');
        if (copyPackageBtn) {
            e.preventDefault();
            copyGeoPackage(copyPackageBtn);
        }
    });
})();
