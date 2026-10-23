<?php

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * 全网外部信源 GEO 引证分发生成器
 *
 * 将站内文章的核心论据与品牌实体，一键改写为知乎、小红书、百家号等外部高权重平台的引证物料，
 * 帮助品牌在外部公域信源中完成实体锚定与证据铺设，形成“官网+公域”双向 AI 召回。
 */
class QiGeo_Offpage_Pack {

    const META_KEY = '_qigeo_offpage_pack';

    public static function init() {
        add_action( 'wp_ajax_qigeo_generate_offpage_pack', array( __CLASS__, 'ajax_generate' ) );
    }

    /**
     * 在文章编辑页渲染【第三阶段：全网 GEO 引证分发包】UI
     *
     * @param WP_Post $post 当前文章
     * @return void
     */
    public static function render_post_box_section( $post ) {
        if ( ! $post instanceof WP_Post ) {
            return;
        }

        $pack = get_post_meta( $post->ID, self::META_KEY, true );
        if ( ! is_array( $pack ) ) {
            $pack = array();
        }

        $zhihu = isset( $pack['zhihu'] ) && is_array( $pack['zhihu'] ) ? $pack['zhihu'] : array();
        $xhs = isset( $pack['xiaohongshu'] ) && is_array( $pack['xiaohongshu'] ) ? $pack['xiaohongshu'] : array();
        $news = isset( $pack['news'] ) && is_array( $pack['news'] ) ? $pack['news'] : array();

        $zhihu_text = ! empty( $zhihu['content'] ) ? (string) $zhihu['content'] : '';
        if ( ! empty( $zhihu['title_suggestion'] ) ) {
            $zhihu_text = "【推荐回答的知乎问题】: " . $zhihu['title_suggestion'] . "\n\n" . $zhihu_text;
        }

        $xhs_text = ! empty( $xhs['content'] ) ? (string) $xhs['content'] : '';
        if ( ! empty( $xhs['title_candidates'] ) && is_array( $xhs['title_candidates'] ) ) {
            $xhs_text = "【备选吸睛标题】:\n" . implode( "\n", $xhs['title_candidates'] ) . "\n\n【笔记正文】:\n" . $xhs_text;
        }
        if ( ! empty( $xhs['tags'] ) ) {
            $xhs_text .= "\n\n" . ( is_array( $xhs['tags'] ) ? implode( ' ', $xhs['tags'] ) : (string) $xhs['tags'] );
        }

        $news_text = ! empty( $news['content'] ) ? (string) $news['content'] : '';
        if ( ! empty( $news['title'] ) ) {
            $news_text = "【新闻通稿标题】: " . $news['title'] . "\n\n" . $news_text;
        }

        $has_content = ! empty( $zhihu_text ) || ! empty( $xhs_text ) || ! empty( $news_text );
        ?>
        <div class="qigeo-offpage-pack-wrap" style="background:#f8fafc;border:1px solid #e2e8f0;border-radius:6px;padding:12px;margin:10px 0;">
            <p style="margin:0 0 8px 0;">
                <button type="button" class="button button-primary qigeo-generate-offpage-btn" data-post-id="<?php echo esc_attr( (string) $post->ID ); ?>">
                    一键生成全网引证包 (知乎 / 小红书 / 资讯)
                </button>
                <span class="spinner qigeo-offpage-spinner" style="float:none;margin-top:2px;"></span>
            </p>
            <p class="description qigeo-offpage-message" style="margin:0 0 10px 0;"></p>

            <div class="qigeo-offpage-content-box" style="<?php echo $has_content ? '' : 'display:none;'; ?>">
                <!-- 知乎深度专业版 -->
                <div style="margin-bottom:12px;background:#fff;border:1px solid #cbd5e1;border-radius:4px;padding:10px;">
                    <div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:6px;">
                        <strong style="color:#0066ff;">🔷 知乎深度答主版 (适合高权重回答)</strong>
                        <button type="button" class="button button-small qigeo-copy-offpage-btn" data-target="#qigeo-offpage-zhihu-field">一键复制知乎回答</button>
                    </div>
                    <textarea id="qigeo-offpage-zhihu-field" class="widefat" rows="7" readonly style="font-size:12px;font-family:sans-serif;"><?php echo esc_textarea( $zhihu_text ); ?></textarea>
                </div>

                <!-- 小红书爆款图文版 -->
                <div style="margin-bottom:12px;background:#fff;border:1px solid #cbd5e1;border-radius:4px;padding:10px;">
                    <div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:6px;">
                        <strong style="color:#ff2442;">📕 小红书爆款图文版 (自带吸睛标题与标签)</strong>
                        <button type="button" class="button button-small qigeo-copy-offpage-btn" data-target="#qigeo-offpage-xhs-field">一键复制小红书笔记</button>
                    </div>
                    <textarea id="qigeo-offpage-xhs-field" class="widefat" rows="7" readonly style="font-size:12px;font-family:sans-serif;"><?php echo esc_textarea( $xhs_text ); ?></textarea>
                </div>

                <!-- 行业快讯通稿版 -->
                <div style="background:#fff;border:1px solid #cbd5e1;border-radius:4px;padding:10px;">
                    <div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:6px;">
                        <strong style="color:#0f172a;">📰 行业快讯 / 百家号通稿版 (适合新闻源收录)</strong>
                        <button type="button" class="button button-small qigeo-copy-offpage-btn" data-target="#qigeo-offpage-news-field">一键复制新闻通稿</button>
                    </div>
                    <textarea id="qigeo-offpage-news-field" class="widefat" rows="7" readonly style="font-size:12px;font-family:sans-serif;"><?php echo esc_textarea( $news_text ); ?></textarea>
                </div>
            </div>
        </div>
        <?php
    }

    /**
     * AJAX 处理全网引证包生成
     */
    public static function ajax_generate() {
        if ( ! current_user_can( 'edit_posts' ) ) {
            wp_send_json_error( array( 'message' => '权限不足' ) );
        }

        check_ajax_referer( 'qigeo_offpage_pack_nonce', 'nonce' );

        $post_id = isset( $_POST['post_id'] ) ? absint( wp_unslash( (string) $_POST['post_id'] ) ) : 0;
        if ( $post_id <= 0 ) {
            wp_send_json_error( array( 'message' => '文章 ID 无效' ) );
        }

        if ( ! current_user_can( 'edit_post', $post_id ) ) {
            wp_send_json_error( array( 'message' => '无该文章编辑权限' ) );
        }

        $post = get_post( $post_id );
        if ( ! $post instanceof WP_Post ) {
            wp_send_json_error( array( 'message' => '文章不存在' ) );
        }

        $settings = class_exists( 'QiGeo_Settings' ) ? QiGeo_Settings::get_settings() : array();
        $provider_id = ! empty( $settings['default_provider_id'] ) ? (string) $settings['default_provider_id'] : '';
        $model_id = ! empty( $settings['default_model_id'] ) ? (string) $settings['default_model_id'] : '';

        $resolved = class_exists( 'QiGeo_Settings' ) ? QiGeo_Settings::resolve_provider_and_model( $provider_id, $model_id ) : null;
        if ( ! is_array( $resolved ) || empty( $resolved['provider'] ) || empty( $resolved['model'] ) ) {
            wp_send_json_error( array( 'message' => '请先在启灵GEO基础设置中配置并启用可用的大模型' ) );
        }

        $provider = $resolved['provider'];
        $model = $resolved['model'];

        // 整理文章事实与实体上下文
        $brand_context = class_exists( 'QiGeo_Brand_Entity_Library' )
            ? QiGeo_Brand_Entity_Library::get_global_profile_context()
            : array();

        $post_summary = (string) QiGeo_Analyzer::get_snapshot_value( $post_id, QiGeo_Analyzer::META_SUMMARY );
        $post_content = mb_substr( wp_strip_all_tags( $post->post_content ), 0, 3500, 'UTF-8' );

        $payload = array(
            'post_title'     => $post->post_title,
            'post_url'       => get_permalink( $post_id ),
            'post_summary'   => $post_summary,
            'post_content'   => $post_content,
            'brand_name'     => ! empty( $brand_context['brand_name'] ) ? $brand_context['brand_name'] : get_bloginfo( 'name' ),
            'target_keyword' => ! empty( $brand_context['target_keyword'] ) ? $brand_context['target_keyword'] : '',
            'site_purpose'   => ! empty( $brand_context['site_purpose'] ) ? $brand_context['site_purpose'] : '',
            'core_products'  => ! empty( $brand_context['core_products'] ) ? (array) $brand_context['core_products'] : array(),
            'authority_proofs' => ! empty( $brand_context['authority_proofs'] ) ? (array) $brand_context['authority_proofs'] : array(),
        );

        $prompt = self::build_prompt();
        $response = QiGeo_AI_Client::analyze_post( $provider, $model, $prompt, $payload, QiGeo_AI_Client::get_interactive_runtime() );

        if ( is_wp_error( $response ) ) {
            wp_send_json_error( array( 'message' => 'AI 生成失败：' . $response->get_error_message() ) );
        }

        $parsed = self::parse_ai_response( $response );
        if ( empty( $parsed ) ) {
            wp_send_json_error( array( 'message' => '解析 AI 返回内容失败，请检查模型输出或重试。' ) );
        }

        // 保存到 post meta
        update_post_meta( $post_id, self::META_KEY, $parsed );

        // 组合格式化文本
        $zhihu = isset( $parsed['zhihu'] ) && is_array( $parsed['zhihu'] ) ? $parsed['zhihu'] : array();
        $xhs = isset( $parsed['xiaohongshu'] ) && is_array( $parsed['xiaohongshu'] ) ? $parsed['xiaohongshu'] : array();
        $news = isset( $parsed['news'] ) && is_array( $parsed['news'] ) ? $parsed['news'] : array();

        $zhihu_text = ! empty( $zhihu['content'] ) ? (string) $zhihu['content'] : '';
        if ( ! empty( $zhihu['title_suggestion'] ) ) {
            $zhihu_text = "【推荐回答的知乎问题】: " . $zhihu['title_suggestion'] . "\n\n" . $zhihu_text;
        }

        $xhs_text = ! empty( $xhs['content'] ) ? (string) $xhs['content'] : '';
        if ( ! empty( $xhs['title_candidates'] ) && is_array( $xhs['title_candidates'] ) ) {
            $xhs_text = "【备选吸睛标题】:\n" . implode( "\n", $xhs['title_candidates'] ) . "\n\n【笔记正文】:\n" . $xhs_text;
        }
        if ( ! empty( $xhs['tags'] ) ) {
            $xhs_text .= "\n\n" . ( is_array( $xhs['tags'] ) ? implode( ' ', $xhs['tags'] ) : (string) $xhs['tags'] );
        }

        $news_text = ! empty( $news['content'] ) ? (string) $news['content'] : '';
        if ( ! empty( $news['title'] ) ) {
            $news_text = "【新闻通稿标题】: " . $news['title'] . "\n\n" . $news_text;
        }

        wp_send_json_success( array(
            'message'    => '全网 GEO 引证分发包已成功生成！',
            'zhihu_text' => $zhihu_text,
            'xhs_text'   => $xhs_text,
            'news_text'  => $news_text,
        ) );
    }

    /**
     * 生成提示词模板
     *
     * @return string
     */
    private static function build_prompt() {
        return '你是一名顶级 GEO（生成式引擎优化）与公域内容操盘专家。
你的任务是将提供的文章、事实论据与品牌实体资料，改写为 3 套适合在外部高权重公域平台发布的高效 GEO 引证物料。
核心目标：通过在知乎、小红书和新闻媒体发布带有可信证据和实体锚定的优质内容，让各大 AI 搜索引擎（豆包、Kimi、Perplexity、DeepSeek）在全网检索时优先引用并推荐该品牌。

请严格输出合法的 JSON 格式（包含 markdown 代码块包裹与否均可），字段结构如下：
{
  "zhihu": {
    "title_suggestion": "适合在知乎回答的热门行业问题，例如：如何选择XXX？有哪些好用的XXX推荐？",
    "content": "知乎深度专业答主回答正文。要求：答主亲切专业口吻、有框架逻辑、有硬核干货拆解、客观公正中自然引用本品牌实体及其优势论据，末尾引导查阅原文或官网进一步了解（500-900字）。"
  },
  "xiaohongshu": {
    "title_candidates": [
      "爆款小红书标题选项1（带情绪或反差）",
      "爆款小红书标题选项2（干货清单体）",
      "爆款小红书标题选项3（避坑指南体）"
    ],
    "content": "小红书图文种草笔记正文。要求：口语化、分段清晰、使用适量 Emoji、有痛点有解决方案、突出真实体验感与品牌亮点（300-500字）。",
    "tags": ["#关键词1", "#关键词2", "#行业词", "#好物推荐"]
  },
  "news": {
    "title": "客观严肃的行业新闻或公关通稿标题",
    "content": "第三人称新闻通稿正文。适合百度百家号、今日头条、搜狐号收录。语言客观严谨，阐明行业痛点与该品牌推出的解决方案，强化权威度（400-600字）。"
  }
}';
    }

    /**
     * 解析 AI 响应
     *
     * @param mixed $response 原始响应
     * @return array<string,mixed>
     */
    private static function parse_ai_response( $response ) {
        $text = '';
        if ( is_string( $response ) ) {
            $text = $response;
        } elseif ( is_array( $response ) ) {
            if ( isset( $response['content'] ) && is_string( $response['content'] ) ) {
                $text = $response['content'];
            } elseif ( isset( $response['raw'] ) && is_string( $response['raw'] ) ) {
                $text = $response['raw'];
            } else {
                $text = wp_json_encode( $response );
            }
        }

        // 剥除可能的 ```json 代码块包裹
        if ( preg_match( '/```(?:json)?\s*([\s\S]*?)\s*```/u', $text, $m ) ) {
            $text = $m[1];
        }

        $decoded = json_decode( trim( $text ), true );
        if ( is_array( $decoded ) && ( isset( $decoded['zhihu'] ) || isset( $decoded['xiaohongshu'] ) || isset( $decoded['news'] ) ) ) {
            return $decoded;
        }

        return array();
    }
}
