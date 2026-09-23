<?php

use App\Support\Search\SearchTokenizer;

/**
 * SearchTokenizer（站内搜索 S1-01）
 *
 * 设计文档 §4.1 规则的逐条固化：中文 bigram、非汉字按词、全角归一、停用词、去重保序、
 * 整 token 截断。这里不碰数据库，纯 PHP，SQLite 与 PG 下行为一致。
 */
describe('SearchTokenizer 分词', function () {
    beforeEach(function () {
        $this->tokenizer = new SearchTokenizer;
    });

    it('中文连续段切成滑窗 bigram', function () {
        expect($this->tokenizer->tokens('北欧实木沙发'))
            ->toBe(['北欧', '欧实', '实木', '木沙', '沙发']);
    });

    it('两字中文只产出一个 token', function () {
        expect($this->tokenizer->tokens('沙发'))->toBe(['沙发']);
    });

    it('单字中文原样输出，不做填充', function () {
        expect($this->tokenizer->tokens('椅'))->toBe(['椅']);
    });

    it('非汉字段按词切分并转小写', function () {
        expect($this->tokenizer->tokens('iPhone15 Pro'))->toBe(['iphone15', 'pro']);
    });

    it('中英混排：两段各自按规则处理', function () {
        expect($this->tokenizer->tokens('北欧实木沙发 iPhone15'))
            ->toBe(['北欧', '欧实', '实木', '木沙', '沙发', 'iphone15']);
    });

    it('数字型号整体保留，不被拆开', function () {
        expect($this->tokenizer->tokens('2024新款'))->toBe(['2024', '新款']);
    });

    it('全角英数与全角空格归一为半角', function () {
        expect($this->tokenizer->tokens('ＡＢＣ１２３'))->toBe(['abc123'])
            ->and($this->tokenizer->tokens('沙发　茶几'))->toBe(['沙发', '茶几']);
    });

    it('标点只作分隔符，不产生 token', function () {
        expect($this->tokenizer->tokens('北欧,实木/沙发'))->toBe(['北欧', '实木', '沙发']);
    });

    it('英文停用词被过滤，中文不受影响', function () {
        expect($this->tokenizer->tokens('the 沙发 of 茶几'))->toBe(['沙发', '茶几']);
    });

    it('重复词去重且保持原有顺序', function () {
        expect($this->tokenizer->tokens('沙发 茶几 沙发'))->toBe(['沙发', '茶几']);
    });

    it('emoji 与纯符号不产生 token', function () {
        expect($this->tokenizer->tokens('沙发👍茶几'))->toBe(['沙发', '茶几'])
            ->and($this->tokenizer->tokens('，。！'))->toBe([])
            // 回归：U+3002「。」曾被 PCRE 判定为 Han 而参与 bigram（见 SearchTokenizer::CJK 注释）
            ->and($this->tokenizer->tokens('沙发。茶几'))->toBe(['沙发', '茶几']);
    });

    it('空串、纯空白、null 语义一律返回空', function () {
        expect($this->tokenizer->tokens(''))->toBe([])
            ->and($this->tokenizer->tokens('   '))->toBe([])
            ->and($this->tokenizer->tokenize(''))->toBe('')
            ->and($this->tokenizer->tokenize('   '))->toBe('');
    });

    it('超长 token（>64 字符）直接丢弃', function () {
        $long = str_repeat('a', SearchTokenizer::MAX_TOKEN_LENGTH + 1);

        expect($this->tokenizer->tokens($long.' 沙发'))->toBe(['沙发']);
    });
});

describe('SearchTokenizer 索引串与降级判定', function () {
    beforeEach(function () {
        $this->tokenizer = new SearchTokenizer;
    });

    it('tokenize 以空格拼接，无连续空格', function () {
        $out = $this->tokenizer->tokenize('北欧 实木沙发 iPhone15');

        expect($out)->toBe('北欧 实木 木沙 沙发 iphone15')
            ->and($out)->not->toContain('  ');
    });

    it('空白是分词边界：不跨段拼 bigram（有意设计）', function () {
        // 「北欧  实木沙发」是两个词，各自成段；跨段拼出「欧实」属噪音。
        // 由此带来的召回损失由降级链的 AND→OR 兜底（设计文档 §4.4）。
        expect($this->tokenizer->tokens('北欧 实木沙发'))->toBe(['北欧', '实木', '木沙', '沙发'])
            ->and($this->tokenizer->tokens('北欧实木沙发'))->toBe(['北欧', '欧实', '实木', '木沙', '沙发']);
    });

    it('截断按整 token 丢弃，绝不切出半个词', function () {
        expect($this->tokenizer->tokenize('北欧实木沙发', 5))->toBe('北欧 欧实')
            ->and($this->tokenizer->tokenize('北欧实木沙发', 2))->toBe('北欧')
            ->and($this->tokenizer->tokenize('北欧实木沙发', 1))->toBe('北欧');
    });

    it('maxLength 为 0 或负数时返回空串', function () {
        expect($this->tokenizer->tokenize('沙发', 0))->toBe('')
            ->and($this->tokenizer->tokenize('沙发', -1))->toBe('');
    });

    it('分词结果幂等：再跑一次不变', function () {
        $once = $this->tokenizer->tokenize('北欧实木沙发 iPhone15 Pro');

        expect($this->tokenizer->tokenize($once))->toBe($once);
    });

    it('isSingleCjkChar：仅恰好一个汉字为真', function () {
        expect($this->tokenizer->isSingleCjkChar('椅'))->toBeTrue()
            ->and($this->tokenizer->isSingleCjkChar(' 椅 '))->toBeTrue()
            ->and($this->tokenizer->isSingleCjkChar('沙发'))->toBeFalse()
            ->and($this->tokenizer->isSingleCjkChar('a'))->toBeFalse()
            ->and($this->tokenizer->isSingleCjkChar(''))->toBeFalse()
            ->and($this->tokenizer->isSingleCjkChar('椅a'))->toBeFalse();
    });

    it('normalize 归一全角与控制字符', function () {
        expect($this->tokenizer->normalize('ＡＢＣ　沙发'))->toBe('ABC 沙发')
            ->and($this->tokenizer->normalize("沙发\n\t茶几"))->toBe('沙发 茶几');
    });
});
