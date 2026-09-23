/* 면허 취득 절차·비용 계산 위저드 엔진.
   license-guide.html(기본, 텍스트 목록)과 license-picker.html(첫 화면만
   이미지 타일)이 이 파일 하나를 함께 씁니다. 두 페이지가 서로 다른
   로직을 갖지 않도록, 데이터·계산·결과화면은 완전히 동일하게 유지하고
   딱 한 곳(renderQuestion의 ROOT 분기)만 화면 모드에 따라 갈립니다.
   이미지 타일 모드를 쓰려면 이 스크립트를 불러오기 전에
   window.YJLG_ROOT_MODE = "images"; 를 설정하면 됩니다. */
(function(){
"use strict";

/* api/license-data.php에서 받아온 DATA(면허별 안내문구·학원 연락처 등, 관리자가
   콘텐츠 관리 화면에서 입력)는 아래에서 innerHTML에 그대로 꽂아 넣기 전에
   반드시 이 함수로 이스케이프합니다. 관리자 계정이 뚫리거나 실수로 <script>
   같은 텍스트를 넣어도, 그게 그대로 실행되지 않고 글자 그대로만 보이게 하기
   위해서입니다 (NODES/opt 같은 코드에 고정된 값은 대상이 아닙니다). */
function esc(s){
  return String(s===null||s===undefined?"":s).replace(/[&<>"']/g, function(ch){
    return {"&":"&amp;","<":"&lt;",">":"&gt;",'"':"&quot;","'":"&#39;"}[ch];
  });
}

/* =======================================================================
   질문 흐름 — 법령·학원 실제 운영기준에 따라 확정된 케이스만 담겨 있음
   ======================================================================= */
var NODES = {
  ROOT: { q:"어떤 면허를 새로 취득하고 싶으신가요?", opts:[
    {t:"제2종 보통면허", go:"B2"},
    {t:"제1종 보통면허", go:"B1"},
    {t:"제2종 소형면허 (이륜차)", go:"S2"},
    {t:"원동기장치자전거면허", go:"WD"},
    {t:"제1종 대형면허", go:"DH"},
    {t:"소형견인차면허", go:"SG"},
    {t:"대형견인차면허", go:"DG"}
  ]},
  B2: { q:"현재 어떤 상태이신가요?", opts:[
    {t:"운전면허가 전혀 없어요 (처음 취득)", code:"B2-01"},
    {t:"원동기장치자전거면허가 있어요", code:"B2-02"},
    {t:"제2종 소형면허(이륜차)가 있어요", code:"B2-03"},
    {t:"제2종 보통면허가 취소되어 다시 따려고 해요", code:"B2-05"},
    {t:"제2종 연습면허가 있어요 (도로주행만 남음)", code:"B2-06"}
  ]},
  B1: { q:"현재 어떤 상태이신가요?", opts:[
    {t:"운전면허가 전혀 없어요 (처음 취득)", go:"B1_COND_NEW"},
    {t:"원동기장치자전거면허가 있어요", go:"B1_COND_WD"},
    {t:"제2종 소형면허(이륜차)가 있어요", go:"B1_COND_S2"},
    {t:"제2종 보통면허가 있어요", go:"TM_HELD"},
    {t:"제1종 대형·특수·소형면허가 있어요", go:"B1_COND_DH"},
    {t:"제1종 보통면허가 취소되어 다시 따려고 해요", go:"B1_COND_CANC"},
    {t:"제2종 보통 + 7년 무사고 운전경력을 증명할 수 있어요", code:"B1-05"},
    {t:"제1종 연습면허가 있어요 (도로주행만 남음)", code:"B1-08"},
    {t:"제1종 보통(자동)면허", code:"CD-01"}
  ]},
  B1_COND_NEW: { q:"취득하려는 제1종 보통면허는 어떤 종류인가요?", opts:[
    {t:"1종보통(자동)", code:"B1-01", cond:"자동"},
    {t:"1종보통(수동)", code:"B1-01", cond:"수동"}
  ]},
  B1_COND_WD: { q:"취득하려는 제1종 보통면허는 어떤 종류인가요?", opts:[
    {t:"1종보통(자동)", code:"B1-02", cond:"자동"},
    {t:"1종보통(수동)", code:"B1-02", cond:"수동"}
  ]},
  B1_COND_S2: { q:"취득하려는 제1종 보통면허는 어떤 종류인가요?", opts:[
    {t:"1종보통(자동)", code:"B1-03", cond:"자동"},
    {t:"1종보통(수동)", code:"B1-03", cond:"수동"}
  ]},
  B1_COND_DH: { q:"취득하려는 제1종 보통면허는 어떤 종류인가요?", opts:[
    {t:"1종보통(자동)", code:"B1-06", cond:"자동"},
    {t:"1종보통(수동)", code:"B1-06", cond:"수동"}
  ]},
  B1_COND_CANC: { q:"취득하려는 제1종 보통면허는 어떤 종류인가요?", opts:[
    {t:"1종보통(자동)", code:"B1-07", cond:"자동"},
    {t:"1종보통(수동)", code:"B1-07", cond:"수동"}
  ]},
  TM_HELD: { q:"보유하신 제2종 보통면허는 어떤 종류인가요?", opts:[
    {t:"2종보통(자동)", go:"TM_TARGET_A"},
    {t:"2종보통(수동)", go:"TM_TARGET_M"}
  ]},
  TM_TARGET_A: { q:"새로 취득하려는 제1종 보통면허는 어떤 종류인가요?", opts:[
    {t:"1종보통(자동)", code:"TM-03"},
    {t:"1종보통(수동)", code:"TM-04"}
  ]},
  TM_TARGET_M: { q:"새로 취득하려는 제1종 보통면허는 어떤 종류인가요?", opts:[
    {t:"1종보통(자동)", code:"TM-02"},
    {t:"1종보통(수동)", code:"TM-01"}
  ]},
  S2: { q:"현재 어떤 상태이신가요?", opts:[
    {t:"운전면허가 전혀 없어요 (처음 취득)", code:"S2-01"},
    {t:"원동기장치자전거면허가 있어요", code:"S2-02"},
    {t:"제2종 보통면허가 있어요", code:"S2-03"},
    {t:"제1종 보통면허가 있어요", code:"S2-04"},
    {t:"제1종 대형·특수면허가 있어요", code:"S2-05"},
    {t:"제2종 소형면허가 취소되어 다시 따려고 해요", code:"S2-06"}
  ]},
  WD: { q:"현재 어떤 상태이신가요?", opts:[
    {t:"운전면허가 전혀 없어요 (처음 취득)", code:"WD-01"},
    {t:"이미 2종보통 이상 또는 2종소형면허가 있어요", code:"WD-02"}
  ]},
  DH: { q:"현재 어떤 상태이신가요?", opts:[
    {t:"운전면허가 전혀 없어요", blocked:"대형면허는 1·2종 보통면허를 먼저 1년 이상 보유해야 취득할 수 있어요. 보통면허부터 취득해주세요."},
    {t:"제1종 보통면허가 있어요", go:"DH_YEAR_1"},
    {t:"제2종 보통면허가 있어요", go:"DH_YEAR_2"},
    {t:"제1종 특수면허가 있어요", code:"DH-05"},
    {t:"제1종 대형면허가 취소되어 다시 따려고 해요", code:"DH-06"}
  ]},
  DH_YEAR_1: { q:"그 제1종 보통면허를 취득하신 지 1년이 지났나요?", opts:[
    {t:"네, 1년이 지났어요", code:"DH-02"},
    {t:"아니요, 아직이에요", blocked:"보통면허 취득일로부터 1년이 지나야 대형면허를 신청할 수 있어요. 1년이 지난 후 다시 방문해주세요."}
  ]},
  DH_YEAR_2: { q:"그 제2종 보통면허를 취득하신 지 1년이 지났나요?", opts:[
    {t:"네, 1년이 지났어요", code:"DH-03"},
    {t:"아니요, 아직이에요", blocked:"보통면허 취득일로부터 1년이 지나야 대형면허를 신청할 수 있어요. 1년이 지난 후 다시 방문해주세요."}
  ]},
  SG: { q:"현재 어떤 상태이신가요?", opts:[
    {t:"운전면허가 전혀 없어요", blocked:"소형견인차면허는 1·2종 보통면허를 먼저 1년 이상 보유해야 취득할 수 있어요. 보통면허부터 취득해주세요."},
    {t:"제1종 또는 제2종 보통면허가 있어요 (1년 경과)", code:"SG-02"},
    {t:"제1종 대형면허가 있어요", code:"SG-03"},
    {t:"소형견인차면허가 취소되어 다시 따려고 해요", code:"SG-04"}
  ]},
  DG: { q:"현재 어떤 상태이신가요?", opts:[
    {t:"운전면허가 전혀 없어요", blocked:"대형견인차면허는 1종 대형면허 또는 특수면허를 먼저 보유해야 취득할 수 있어요."},
    {t:"제1종 대형면허가 있어요", code:"DG-02"},
    {t:"제1종 특수면허가 있어요", code:"DG-03"},
    {t:"소형견인차면허가 있어요", code:"DG-04"},
    {t:"대형견인차면허가 취소되어 다시 따려고 해요", code:"DG-05"}
  ]}
};

var STATUS_LABEL = {
  ok:{cls:"yjlg-ok", text:"신청 가능"},
  warn:{cls:"yjlg-warn", text:"상담 후 확정"},
  unknown:{cls:"yjlg-warn", text:"상담 필요"},
  doc:{cls:"yjlg-info", text:"서류로 전환 가능"},
  blocked:{cls:"yjlg-danger", text:"지금은 어려워요"}
};

/* 학원 실제 데이터 — 가격·법령이 바뀌면 이 아래 DATA만 고치면 됩니다 */
/* DATA_START */
var DATA = {
  updatedAt:"2026-08-31",
  contact:{ name:"(주)영진자동차운전전문학원", phone:"062-951-5100", phoneHref:"0629515100", address:"광주광역시 광산구 목련로 372-19" },
  rates:{
    hak: 10000,
    types:{
      B2:{ gi:55000, doro:55000, hak:null, examGi:50000, examDoro:50000 },
      B1:{ gi:55000, doro:55000, hak:null, examGi:50000, examDoro:50000 },
      TM:{ gi:55000, doro:55000, hak:null, examGi:50000, examDoro:50000 },
      CD:{ gi:55000, doro:55000, hak:null, examGi:50000, examDoro:50000 },
      S2:{ gi:40000, doro:null, hak:0, examGi:40000, examDoro:null },
      WD:{ gi:42500, doro:null, hak:null, examGi:40000, examDoro:null },
      DH:{ gi:68000, doro:null, hak:11000, examGi:68000, examDoro:null },
      SG:{ gi:70000, doro:null, hak:null, examGi:70000, examDoro:null },
      DG:{ gi:80000, doro:null, hak:null, examGi:70000, examDoro:null }
    }
  },
  cases:{
    "B2-01":{status:"ok", target:"제2종 보통면허", hours:{hak:[3,3],gi:[4,4],doro:[6,6]}, cost:{hak:30000,gi:220000,doro:330000}, note:"처음 취득하시는 표준 과정이에요."},
    "B2-02":{status:"ok", target:"제2종 보통면허", hours:{hak:[2,2],gi:[4,4],doro:[6,6]}, cost:{hak:20000,gi:220000,doro:330000}, note:"원동기면허가 있어 학과교육이 2시간으로 줄어들어요."},
    "B2-03":{status:"ok", target:"제2종 보통면허", hours:{hak:[2,2],gi:[4,4],doro:[6,6]}, cost:{hak:20000,gi:220000,doro:330000}, note:"2종소형면허가 있어 학과교육이 2시간으로 줄어들어요."},
    "B2-05":{status:"ok", target:"제2종 보통면허", hours:{hak:[1,1],gi:[2,2],doro:[6,6]}, cost:{hak:10000,gi:110000,doro:330000}, note:"취소 후 재취득 할인 과정이 적용돼요."},
    "B2-06":{status:"ok", target:"제2종 보통면허", hours:{hak:[0,0],gi:[0,0],doro:[6,6]}, cost:{hak:0,gi:0,doro:330000}, note:"학과·기능 교육을 이미 마치셨어요. 도로주행만 남았어요."},
    "B1-01":{status:"ok", target:"제1종 보통면허", hours:{hak:[3,3],gi:[4,4],doro:[6,6]}, cost:{hak:30000,gi:220000,doro:330000}, note:"처음 취득하시는 표준 과정이에요."},
    "B1-02":{status:"ok", target:"제1종 보통면허", hours:{hak:[2,2],gi:[4,4],doro:[6,6]}, cost:{hak:20000,gi:220000,doro:330000}, note:"원동기면허가 있어 학과교육이 2시간으로 줄어들어요."},
    "B1-03":{status:"ok", target:"제1종 보통면허", hours:{hak:[2,2],gi:[4,4],doro:[6,6]}, cost:{hak:20000,gi:220000,doro:330000}, note:"2종소형면허가 있어 학과교육이 2시간으로 줄어들어요."},
    "TM-01":{status:"ok", target:"제1종 보통면허(수동)", hours:{hak:[0,0],gi:[0,0],doro:[3,3]}, cost:{hak:0,gi:0,doro:165000}, note:"2종보통(수동) 보유자의 표준 전환 과정이에요. 기능교육은 면제돼요."},
    "TM-02":{status:"warn", target:"제1종 보통면허(자동)", hours:{hak:[0,0],gi:[0,0],doro:[3,3]}, cost:{hak:0,gi:0,doro:165000}, note:"실제 사례가 드문 조합이라 상담을 통해 한 번 더 확인해드려요."},
    "TM-03":{status:"ok", target:"제1종 보통면허(자동)", hours:{hak:[0,0],gi:[0,0],doro:[3,3]}, cost:{hak:0,gi:0,doro:165000}, note:"자동↔자동 전환이라 기능교육이 면제돼요."},
    "TM-04":{status:"ok", target:"제1종 보통면허(수동)", hours:{hak:[0,0],gi:[0,0],doro:[6,6]}, cost:{hak:0,gi:0,doro:330000}, note:"학과·장내기능 교육이 모두 면제되고, 도로주행 6시간 이수 후 도로주행 시험만 보시면 돼요."},
    "CD-01":{status:"ok", target:"제1종 보통면허(수동)", hours:{hak:[0,0],gi:[4,4],doro:[0,0]}, cost:{hak:0,gi:220000,doro:0}, note:"1종보통(자동) 보유자가 수동으로 전환하는 과정이에요. 학과·도로주행은 면제되고, 장내기능 4시간 이수 후 장내기능 시험만 보시면 돼요."},
    "B1-05":{status:"doc", target:"제1종 보통면허", note:"학과·기능·도로주행 시험 없이 서류(운전경력증명서 등)로 전환할 수 있어요. 정확한 절차와 수수료는 상담 시 안내해드려요."},
    "B1-06":{status:"ok", target:"제1종 보통면허", hours:{hak:[3,3],gi:[4,4],doro:[6,6]}, cost:{hak:30000,gi:220000,doro:330000}, note:"대형·특수·소형면허 보유자에게 별도 감면 규정이 없어, 처음 취득하시는 경우와 동일한 기준이 적용돼요."},
    "B1-07":{status:"ok", target:"제1종 보통면허", hours:{hak:[1,1],gi:[2,2],doro:[6,6]}, cost:{hak:10000,gi:110000,doro:330000}, note:"취소 후 재취득 할인 과정이 적용돼요."},
    "B1-08":{status:"ok", target:"제1종 보통면허", hours:{hak:[0,0],gi:[0,0],doro:[6,6]}, cost:{hak:0,gi:0,doro:330000}, note:"학과·기능 교육을 이미 마치셨어요. 도로주행만 남았어요."},
    "S2-01":{status:"ok", target:"제2종 소형면허", hours:{hak:[3,3],gi:[10,10],doro:null}, cost:{hak:0,gi:400000,doro:null}, note:"학과교육 비용은 별도로 받지 않아요."},
    "S2-02":{status:"ok", target:"제2종 소형면허", hours:{hak:[0,0],gi:[6,6],doro:null}, cost:{hak:0,gi:240000,doro:null}, note:"원동기면허가 있어 학과는 면제, 기능교육도 할인돼요."},
    "S2-03":{status:"ok", target:"제2종 소형면허", hours:{hak:[3,3],gi:[10,10],doro:null}, cost:{hak:0,gi:400000,doro:null}, note:"학과교육 시간 감면 사유는 없고, 학과교육 비용만 별도로 받지 않아요."},
    "S2-04":{status:"ok", target:"제2종 소형면허", hours:{hak:[3,3],gi:[10,10],doro:null}, cost:{hak:0,gi:400000,doro:null}, note:"학과교육 시간 감면 사유는 없고, 학과교육 비용만 별도로 받지 않아요."},
    "S2-05":{status:"ok", target:"제2종 소형면허", hours:{hak:[3,3],gi:[10,10],doro:null}, cost:{hak:0,gi:400000,doro:null}, note:"학과교육 시간 감면 사유는 없고, 학과교육 비용만 별도로 받지 않아요."},
    "S2-06":{status:"ok", target:"제2종 소형면허", hours:{hak:[3,3],gi:[10,10],doro:null}, cost:{hak:0,gi:400000,doro:null}, note:"취소 후 재취득도 신규와 같은 기준이 적용돼요."},
    "WD-01":{status:"ok", target:"원동기장치자전거면허", hours:{hak:[3,3],gi:[8,8],doro:null}, cost:{hak:30000,gi:340000,doro:null}, note:"처음 취득하시는 표준 과정이에요."},
    "WD-02":{status:"ok", target:"원동기장치자전거면허", hours:{hak:[3,3],gi:[8,8],doro:null}, cost:{hak:30000,gi:340000,doro:null}, note:"다른 면허가 있어도 원동기는 별도로 표준 과정을 이수해요."},
    "DH-02":{status:"ok", target:"제1종 대형면허", hours:{hak:[3,3],gi:[10,10],doro:null}, cost:{hak:33000,gi:680000,doro:null}, note:"감면 조항이 없어 표준 기준이 그대로 적용돼요."},
    "DH-03":{status:"ok", target:"제1종 대형면허", hours:{hak:[3,3],gi:[10,10],doro:null}, cost:{hak:33000,gi:680000,doro:null}, note:"감면 조항이 없어 표준 기준이 그대로 적용돼요."},
    "DH-05":{status:"ok", target:"제1종 대형면허", hours:{hak:[3,3],gi:[10,10],doro:null}, cost:{hak:33000,gi:680000,doro:null}, note:"감면 조항이 없어 표준 기준이 그대로 적용돼요."},
    "DH-06":{status:"ok", target:"제1종 대형면허", hours:{hak:[3,3],gi:[10,10],doro:null}, cost:{hak:33000,gi:680000,doro:null}, note:"취소 후 재취득도 동일한 요금이 적용돼요."},
    "SG-02":{status:"ok", target:"소형견인차면허", hours:{hak:[3,3],gi:[4,4],doro:null}, cost:{hak:30000,gi:280000,doro:null}, note:"감면 조항이 없어 표준 기준이 그대로 적용돼요."},
    "SG-03":{status:"ok", target:"소형견인차면허", hours:{hak:[3,3],gi:[4,4],doro:null}, cost:{hak:30000,gi:280000,doro:null}, note:"감면 조항이 없어 표준 기준이 그대로 적용돼요."},
    "SG-04":{status:"ok", target:"소형견인차면허", hours:{hak:[3,3],gi:[4,4],doro:null}, cost:{hak:30000,gi:280000,doro:null}, note:"취소 후 재취득도 동일한 요금이 적용돼요."},
    "DG-02":{status:"ok", target:"대형견인차면허", hours:{hak:[3,3],gi:[10,10],doro:null}, cost:{hak:30000,gi:800000,doro:null}, note:"감면 조항이 없어 표준 기준이 그대로 적용돼요."},
    "DG-03":{status:"ok", target:"대형견인차면허", hours:{hak:[3,3],gi:[10,10],doro:null}, cost:{hak:30000,gi:800000,doro:null}, note:"감면 조항이 없어 표준 기준이 그대로 적용돼요."},
    "DG-04":{status:"ok", target:"대형견인차면허", hours:{hak:[3,3],gi:[10,10],doro:null}, cost:{hak:30000,gi:800000,doro:null}, note:"감면 조항이 없어 표준 기준이 그대로 적용돼요."},
    "DG-05":{status:"ok", target:"대형견인차면허", hours:{hak:[3,3],gi:[10,10],doro:null}, cost:{hak:30000,gi:800000,doro:null}, note:"취소 후 재취득도 동일한 요금이 적용돼요."}
  }
};
/* DATA_END */

/* =======================================================================
   아이콘
   ======================================================================= */
function svgChevron(){return '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="m9 6 6 6-6 6"/></svg>';}
function svgBack(){return '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="m15 6-6 6 6 6"/></svg>';}
function svgReset(){return '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M3 12a9 9 0 1 0 3-6.7"/><path d="M3 4v5h5"/></svg>';}
function svgPhone(){return '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M22 16.92v3a2 2 0 0 1-2.18 2 19.79 19.79 0 0 1-8.63-3.07 19.5 19.5 0 0 1-6-6 19.79 19.79 0 0 1-3.07-8.67A2 2 0 0 1 4.11 2h3a2 2 0 0 1 2 1.72c.13.96.36 1.9.68 2.81a2 2 0 0 1-.45 2.11L8.09 9.91a16 16 0 0 0 6 6l1.27-1.27a2 2 0 0 1 2.11-.45c.91.32 1.85.55 2.81.68A2 2 0 0 1 22 16.92z"/></svg>';}
function svgPin(){return '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M20 10c0 6-8 12-8 12s-8-6-8-12a8 8 0 0 1 16 0Z"/><circle cx="12" cy="10" r="3"/></svg>';}
function svgCar(){return '<svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"><rect x="3" y="7" width="18" height="12" rx="2"/><path d="M7 7V5a2 2 0 0 1 2-2h6a2 2 0 0 1 2 2v2"/><circle cx="8" cy="15" r="1.2"/><circle cx="16" cy="15" r="1.2"/></svg>';}
function svgCalendar(){return '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><rect x="3" y="4" width="18" height="18" rx="2"/><path d="M16 2v4M8 2v4M3 10h18"/></svg>';}
function svgClock(){return '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><circle cx="12" cy="12" r="9"/><path d="M12 7v5l3 3"/></svg>';}
function svgChecklist(){return '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M9 11l2 2 4-4"/><rect x="3" y="3" width="18" height="18" rx="2"/></svg>';}
function svgFlag(){return '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M4 22V3"/><path d="M4 4h13l-2.5 4L17 12H4"/></svg>';}

/* =======================================================================
   표기 형식 헬퍼
   ======================================================================= */
function won(n){ return n.toLocaleString("ko-KR")+"원"; }
function hourCell(arr){
  if(arr===null||arr===undefined) return {text:"해당없음", dim:true};
  return {text:arr[0]+"시간", dim:false};
}
function costCell(v){
  if(v===null||v===undefined) return {text:"해당없음", dim:true};
  if(v==="unknown") return {text:"문의", dim:true};
  if(v===0) return {text:"면제", dim:false};
  return {text:won(v), dim:false};
}
function calcTotalWithExam(cost, examGi, examDoro){
  if(!cost) return "unknown";
  var sum=0, any=false;
  ["hak","gi","doro"].forEach(function(k){
    var v=cost[k];
    if(typeof v==="number"){ sum+=v; any=true; }
  });
  [examGi, examDoro].forEach(function(v){
    if(typeof v==="number"){ sum+=v; any=true; }
  });
  if(!any) return "unknown";
  return sum;
}
function examFeeNumbers(code){
  var prefix=code.split("-")[0];
  var t = DATA.rates && DATA.rates.types && DATA.rates.types[prefix];
  return t ? { examGi: t.examGi, examDoro: t.examDoro } : { examGi: null, examDoro: null };
}

/* =======================================================================
   과정 · 기간 · 교육시간 · 준비물 · 시험일정 — 수강생이 가장 궁금해하는 6가지 중
   비용(교육시간·수강료·시험료)을 제외한 나머지를 기존 hours 데이터로부터 구성
   ======================================================================= */
var SCHEDULE_BY_PREFIX = {
  B2:{ label:"1·2종 보통면허 · 2종소형면허", lines:["화·수·토 10:00 ~ 12:50","목요일 13:30 ~ 16:20"] },
  B1:{ label:"1·2종 보통면허 · 2종소형면허", lines:["화·수·토 10:00 ~ 12:50","목요일 13:30 ~ 16:20"] },
  TM:{ label:"1·2종 보통면허 · 2종소형면허", lines:["화·수·토 10:00 ~ 12:50","목요일 13:30 ~ 16:20"] },
  CD:{ label:"1·2종 보통면허 · 2종소형면허", lines:["화·수·토 10:00 ~ 12:50","목요일 13:30 ~ 16:20"] },
  S2:{ label:"1·2종 보통면허 · 2종소형면허", lines:["화·수·토 10:00 ~ 12:50","목요일 13:30 ~ 16:20"] },
  DH:{ label:"대형·특수면허", lines:["화·수·토 09:00 ~ 11:50","목요일 14:30 ~ 17:20"] },
  SG:{ label:"대형·특수면허", lines:["화·수·토 09:00 ~ 11:50","목요일 14:30 ~ 17:20"] },
  DG:{ label:"대형·특수면허", lines:["화·수·토 09:00 ~ 11:50","목요일 14:30 ~ 17:20"] }
};

function renderFlow(c){
  var steps=[];
  var hakOn = c.hours.hak && c.hours.hak[0]>0;
  var giOn = c.hours.gi && c.hours.gi[0]>0;
  var doroOn = c.hours.doro && c.hours.doro[0]>0;
  steps.push({t:"학과교육", on:hakOn});
  steps.push({t:"장내기능교육·시험", on:giOn});
  steps.push({t:"도로주행교육·시험", on:doroOn});
  steps.push({t:"면허증 발급", on:true, always:true});
  var html='<div class="yjlg-section-label">취득 과정</div><div class="yjlg-flow">';
  steps.forEach(function(s,i){
    if(i>0) html+='<span class="yjlg-flow-arrow">→</span>';
    html+='<span class="yjlg-flow-step'+(s.on?"":" yjlg-dim")+'"><span class="yjlg-flow-n">'+(i+1)+'</span>'+s.t+(s.on?"":" (면제)")+'</span>';
  });
  html+='</div>';
  return html;
}

function estimateDuration(c){
  var total=0;
  ["hak","gi","doro"].forEach(function(k){
    if(c.hours[k] && typeof c.hours[k][0]==="number") total+=c.hours[k][0];
  });
  var text;
  if(total<=0) text="바로 신청 가능 (별도 의무교육 없음)";
  else if(total<=3) text="최短 1~2일";
  else if(total<=9) text="약 3~6일";
  else if(total<=19) text="약 1~2주";
  else text="약 2~3주";
  return ""
    +'<div class="yjlg-info-block"><div class="yjlg-info-head">'+svgCalendar()+'예상 소요기간</div>'
    +'<p><b style="color:var(--ink)">'+text+'</b> (교육시간 합계 '+total+'시간 기준)</p>'
    +'<p>실제 소요기간은 학원 스케줄과 개인 진도, 시험 재응시 여부에 따라 달라질 수 있어요.</p></div>';
}

function renderScheduleBlock(code, c){
  var prefix=code.split("-")[0];
  var hakOn = c.hours.hak && c.hours.hak[0]>0;
  var sch = SCHEDULE_BY_PREFIX[prefix];
  var html='<div class="yjlg-info-block"><div class="yjlg-info-head">'+svgClock()+'교육 가능 시간</div>';
  if(!hakOn){
    html+='<p>학과교육이 면제되어 별도 시간표 확인이 필요 없어요.</p>';
  } else if(sch){
    html+='<p>'+sch.lines.join("<br>")+' (학과교육 기준)</p>';
    html+='<p>매달 첫째 주 일요일은 토요일과 동일하게 수업을 진행해요.</p>';
  } else {
    html+='<p>정확한 학과교육 시간은 학원에 문의해주세요.</p>';
  }
  html+='</div>';
  return html;
}

function renderPrepBlock(){
  return ""
    +'<div class="yjlg-info-block"><div class="yjlg-info-head">'+svgChecklist()+'준비물</div><ul>'
    +'<li>신분증 — 주민등록증·운전면허증·여권·청소년증·공무원증·장애인복지카드·외국인등록증 중 하나</li>'
    +'<li>여권용 증명사진 3장 (3.5×4.5cm, 최근 6개월 이내 촬영)</li>'
    +'<li>수강료 결제수단 (현금·카드)</li>'
    +'<li><a class="yjlg-link" href="medical-checkup-hospitals.html">신체검사</a> 대상자는 병원에서 미리 받은 신체검사 결과 (학원에서는 진행하지 않아요)</li>'
    +'</ul></div>';
}

function renderExamScheduleBlock(effExamGi, effExamDoro){
  var names=[];
  if(effExamGi!==null) names.push("장내기능시험");
  if(effExamDoro!==null) names.push("도로주행시험");
  var html='<div class="yjlg-info-block"><div class="yjlg-info-head">'+svgFlag()+'시험 일정</div>';
  if(names.length){
    html+='<p>'+names.join('·')+'은(는) 학원에서 정기적으로 진행돼요. 정확한 일정은 접수 시 상담을 통해 안내해드려요.</p>';
  } else {
    html+='<p>이 과정은 별도의 실기 시험이 없어요.</p>';
  }
  html+='</div>';
  return html;
}

/* =======================================================================
   상태
   ======================================================================= */
var history=[];
var terminal=null;
var root;

/* =======================================================================
   화면 렌더링
   ======================================================================= */
function currentNodeId(){ return history.length ? history[history.length-1].opt.go : "ROOT"; }
function choose(opt){
  history.push({opt:opt});
  if(opt.code){ terminal={type:"code", code:opt.code, cond:opt.cond||null}; }
  else if(opt.blocked){ terminal={type:"blocked", msg:opt.blocked}; }
  else{ terminal=null; }
  render();
}
function goBack(){
  if(terminal){ history.pop(); terminal=null; render(); return; }
  if(!history.length) return;
  history.pop();
  render();
}
function resetAll(){ history=[]; terminal=null; render(); }

function contactBox(){
  var c=DATA.contact||{};
  return ""
    +'<div class="yjlg-contact-box">'
      +'<div class="yjlg-head">학원에 문의하기</div>'
      +'<div class="yjlg-contact-row">'+svgPhone()+' <a href="tel:'+esc(c.phoneHref||"")+'">'+esc(c.phone||"")+'</a></div>'
      +'<div class="yjlg-contact-row">'+svgPin()+' '+esc(c.address||"")+'</div>'
    +'</div>';
}

/* =======================================================================
   상담 신청 — 결과 화면의 "이 과정 상담 신청하기"
   과정·예상금액을 함께 담아 api/contact.php(action=consult)로 보냅니다.
   관리자 화면 "문의 관리"에 그대로 들어가고, 관리자 메인에 새 문의 뱃지가 뜹니다.
   스타일은 license-guide.html/license-picker.html 두 곳에 똑같이 넣지 않도록
   여기서 한 번만 주입합니다.
   ======================================================================= */
var CONSULT_CSS=""
+"#yj-license-guide .yjc-cta{margin:4px 0 22px;}"
+"#yj-license-guide .yjc-cta-btn{width:100%;display:flex;align-items:center;justify-content:center;gap:8px;background:var(--color-primary);color:#fff;border:none;border-radius:var(--radius-md);padding:16px;font-family:'Noto Sans KR',sans-serif;font-size:1.02rem;font-weight:700;cursor:pointer;box-shadow:0 6px 16px rgba(109,40,217,.28);}"
+"#yj-license-guide .yjc-cta-btn svg{width:18px;height:18px;}"
+"#yj-license-guide .yjc-cta-sub{text-align:center;font-size:.8rem;color:var(--muted);margin:8px 0 0;}"
+"#yj-license-guide .yjc-cta-sub a{color:var(--muted);text-decoration:underline;}"
+"#yj-license-guide .yjc-form{border:1px solid rgba(109,40,217,.35);background:var(--surface);border-radius:var(--radius-md);padding:18px 16px 16px;margin:4px 0 22px;}"
+"#yj-license-guide .yjc-form h3{font-family:'Gothic A1',sans-serif;font-weight:800;font-size:1.08rem;margin:0 0 12px;}"
+"#yj-license-guide .yjc-summary{background:var(--info-bg);border-radius:var(--radius-sm);padding:12px 14px;margin-bottom:16px;font-size:.86rem;line-height:1.65;}"
+"#yj-license-guide .yjc-summary .yjc-sum-k{color:var(--info-text);font-weight:700;font-size:.74rem;margin-bottom:4px;display:block;}"
+"#yj-license-guide .yjc-summary b{color:var(--ink);}"
+"#yj-license-guide .yjc-field{margin-bottom:14px;}"
+"#yj-license-guide .yjc-field>label,#yj-license-guide .yjc-field>.yjc-label{display:block;font-size:.84rem;font-weight:700;margin-bottom:6px;}"
+"#yj-license-guide .yjc-req{color:var(--danger-text);margin-left:2px;}"
+"#yj-license-guide .yjc-opt{color:var(--muted);font-weight:400;margin-left:4px;font-size:.78rem;}"
+"#yj-license-guide .yjc-field input[type=text],#yj-license-guide .yjc-field input[type=tel],#yj-license-guide .yjc-field textarea{width:100%;border:1px solid var(--line);border-radius:var(--radius-sm);padding:11px 12px;font-size:.95rem;font-family:'Noto Sans KR',sans-serif;background:#fff;color:#221c33;}"
+"#yj-license-guide .yjc-field textarea{resize:vertical;min-height:74px;}"
+"#yj-license-guide .yjc-agree{display:flex;gap:8px;align-items:flex-start;font-size:.82rem;color:var(--muted);margin:4px 0 14px;line-height:1.5;}"
+"#yj-license-guide .yjc-agree input{margin-top:3px;}"
+"#yj-license-guide .yjc-agree a{color:var(--muted);}"
+"#yj-license-guide .yjc-submit{width:100%;background:var(--color-primary);color:#fff;border:none;border-radius:var(--radius-md);padding:14px;font-size:.98rem;font-weight:700;font-family:'Noto Sans KR',sans-serif;cursor:pointer;}"
+"#yj-license-guide .yjc-submit:disabled{opacity:.6;cursor:default;}"
+"#yj-license-guide .yjc-error{color:var(--danger-text);font-size:.84rem;margin:10px 0 0;text-align:center;}"
+"#yj-license-guide .yjc-alt{text-align:center;font-size:.8rem;color:var(--muted);margin:10px 0 0;}"
+"#yj-license-guide .yjc-alt a{color:var(--color-primary);font-weight:700;}"
+"#yj-license-guide .yjc-done{background:var(--success-bg);color:var(--success-text);border-radius:var(--radius-md);padding:18px 16px;margin:4px 0 22px;line-height:1.6;font-size:.9rem;}"
+"#yj-license-guide .yjc-done strong{display:block;font-size:1.02rem;margin-bottom:4px;}"
+"#yj-license-guide .yjc-done small{display:block;font-size:.8rem;opacity:.85;margin-top:4px;}"
+"#yj-license-guide .yjf-box{background:#fff;border:1px solid var(--line);border-radius:var(--radius-md);padding:14px 12px 12px;}"
+"#yj-license-guide .yjf-row{display:flex;align-items:center;justify-content:center;gap:6px;}"
+"#yj-license-guide .yjf-colon{font-family:'Oswald',sans-serif;font-size:48px;font-weight:600;color:#1E1A2B;line-height:1;}"
+"#yj-license-guide .flip{position:relative;width:88px;height:108px;border-radius:12px;background:#1E1A2B;perspective:400px;box-shadow:0 4px 10px rgba(30,26,43,.25);user-select:none;-webkit-user-select:none;flex:none;}"
+"#yj-license-guide .flip .half{position:absolute;left:0;right:0;height:50%;overflow:hidden;background:#1E1A2B;}"
+"#yj-license-guide .flip .top{top:0;border-radius:12px 12px 0 0;}"
+"#yj-license-guide .flip .bottom{bottom:0;border-radius:0 0 12px 12px;}"
+"#yj-license-guide .flip .half span{position:absolute;left:0;right:0;height:200%;display:flex;align-items:center;justify-content:center;font-family:'Oswald',sans-serif;font-weight:600;font-size:50px;color:#fff;}"
+"#yj-license-guide.yjf-font-ok .flip .half span{font-size:68px;}"
+"#yj-license-guide .flip .top span{top:0;}"
+"#yj-license-guide .flip .bottom span{bottom:0;}"
+"#yj-license-guide .flip::after{content:'';position:absolute;left:0;right:0;top:50%;height:2px;margin-top:-1px;background:rgba(0,0,0,.6);z-index:5;}"
+"#yj-license-guide .flip .flap-top{transform-origin:50% 100%;z-index:3;animation:yjfTop .22s ease-in forwards;backface-visibility:hidden;-webkit-backface-visibility:hidden;}"
+"#yj-license-guide .flip .flap-bottom{transform-origin:50% 0;z-index:4;transform:rotateX(90deg);animation:yjfBottom .22s ease-out .22s forwards;backface-visibility:hidden;-webkit-backface-visibility:hidden;}"
+"@keyframes yjfTop{to{transform:rotateX(-90deg);}}"
+"@keyframes yjfBottom{to{transform:rotateX(0deg);}}"
+"#yj-license-guide .yjf-hit{position:absolute;left:0;right:0;height:50%;z-index:6;background:none;border:none;padding:0;cursor:pointer;display:flex;justify-content:center;color:rgba(255,255,255,.45);}"
+"#yj-license-guide .yjf-hit.up{top:0;align-items:flex-start;padding-top:2px;}"
+"#yj-license-guide .yjf-hit.dn{bottom:0;align-items:flex-end;padding-bottom:2px;}"
+"#yj-license-guide .yjf-hit svg{width:16px;height:16px;}"
+"#yj-license-guide .yjf-hit:disabled{cursor:default;color:transparent;}"
+"#yj-license-guide .yjf-hit:focus-visible{outline:2px solid #fff;outline-offset:-4px;border-radius:10px;}"
+"#yj-license-guide .yjf-sum{text-align:center;margin-top:12px;font-size:.92rem;color:#221c33;}"
+"#yj-license-guide .yjf-sum b{color:var(--color-primary);}"
+"#yj-license-guide .yjf-help{text-align:center;font-size:.76rem;color:var(--muted);margin-top:4px;line-height:1.5;}"
+"#yj-license-guide .yjf-notice{margin-top:10px;background:var(--info-bg);color:var(--info-text);border-radius:var(--radius-sm);padding:9px 11px;font-size:.8rem;line-height:1.55;text-align:center;}";

var CONSULT_MIN=9*60, CONSULT_MAX=18*60, CONSULT_STEP=30;
var consultInfo=null;

function ensureConsultAssets(){
  if(document.getElementById("yjlg-consult-css")) return;
  var st=document.createElement("style");
  st.id="yjlg-consult-css";
  st.textContent=CONSULT_CSS;
  document.head.appendChild(st);
  /* 숫자 글꼴(Oswald)이 실제로 받아졌을 때만 숫자를 크게 키웁니다.
     못 받으면 기본 글꼴은 폭이 넓어서, 작은 크기 그대로 둬야 카드 밖으로 안 넘칩니다.
     글꼴 정의(@font-face)는 이 스타일시트가 다 받아진 뒤에야 브라우저가 알게 되므로
     onload 뒤에 요청해야 합니다 (바로 요청하면 "그런 글꼴 없음"으로 끝나버립니다). */
  var fl=document.createElement("link");
  fl.rel="stylesheet";
  fl.href="https://fonts.googleapis.com/css2?family=Oswald:wght@600&display=swap";
  fl.onload=function(){
    if(!document.fonts || !document.fonts.load) return;
    document.fonts.load("600 68px Oswald").then(function(list){
      if(list && list.length && root){ root.classList.add("yjf-font-ok"); }
    }).catch(function(){});
  };
  document.head.appendChild(fl);
}

function two(n){ return (n<10?"0":"")+n; }
function hhmm(m){ return two(Math.floor(m/60))+":"+two(m%60); }

function consultSlot(){
  var c=DATA.contact||{};
  var chat='<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M21 11.5a8.4 8.4 0 0 1-.9 3.8 8.5 8.5 0 0 1-7.6 4.7 8.4 8.4 0 0 1-3.8-.9L3 21l1.9-5.7a8.4 8.4 0 0 1-.9-3.8 8.5 8.5 0 0 1 4.7-7.6 8.4 8.4 0 0 1 3.8-.9h.5a8.5 8.5 0 0 1 8 8v.5z"/></svg>';
  return '<div id="yjc-slot"><div class="yjc-cta">'
    +'<button type="button" class="yjc-cta-btn" onclick="__yjwiz.consult()">'+chat+'이 과정 상담 신청하기</button>'
    +'<p class="yjc-cta-sub">선택하신 과정과 예상 금액이 상담원에게 함께 전달돼요'
    +(c.phoneHref?' · 급하시면 <a href="tel:'+esc(c.phoneHref)+'">전화 상담</a>':'')+'</p>'
    +'</div></div>';
}

function openConsult(){
  var slot=document.getElementById("yjc-slot");
  if(!slot || !consultInfo) return;
  var c=DATA.contact||{};
  var info=consultInfo;
  slot.innerHTML='<form class="yjc-form" id="yjc-form" novalidate>'
    +'<h3>상담 신청</h3>'
    +'<div class="yjc-summary"><span class="yjc-sum-k">상담 요청 과정 (자동으로 함께 전달돼요)</span>'
      +'<b>'+esc(info.title)+'</b><br>'+esc(info.path)+' · 코드 '+esc(info.code)
      +(info.total?'<br>예상 합계 <b>'+esc(info.total)+'</b>':'')+'</div>'
    +'<div class="yjc-field"><label for="yjc-name">이름<span class="yjc-req">*</span></label><input type="text" id="yjc-name" maxlength="50" autocomplete="name"></div>'
    +'<div class="yjc-field"><label for="yjc-phone">연락처<span class="yjc-req">*</span></label><input type="tel" id="yjc-phone" maxlength="30" placeholder="010-0000-0000" autocomplete="tel"></div>'
    +'<div class="yjc-field"><span class="yjc-label" id="yjc-time-label">연락 가능한 시간<span class="yjc-req">*</span></span><div class="yjf-box" id="yjf-box" role="group" aria-labelledby="yjc-time-label"></div></div>'
    +'<div class="yjc-field"><label for="yjc-msg">더 궁금한 점<span class="yjc-opt">(선택)</span></label><textarea id="yjc-msg" maxlength="2000" placeholder="예) 주말에도 수업 들을 수 있나요?"></textarea></div>'
    +'<label class="yjc-agree"><input type="checkbox" id="yjc-agree"><span>상담 연락을 위한 개인정보 수집·이용에 동의합니다. (수집 항목: 이름·연락처, 선택 입력한 문의 내용 / 보유기간: 접수일로부터 1개월 · 동의하지 않으시면 신청하실 수 없어요 · <a href="privacy.html" target="_blank" rel="noopener">자세히</a>)</span></label>'
    +'<button type="submit" class="yjc-submit" id="yjc-submit">상담 신청 보내기</button>'
    +'<p class="yjc-error" id="yjc-error" role="alert"></p>'
    +(c.phoneHref?'<p class="yjc-alt">급하시면 <a href="tel:'+esc(c.phoneHref)+'">'+esc(c.phone||c.phoneHref)+'</a></p>':'')
    +'</form>';
  var time=installFlipClock(document.getElementById("yjf-box"));
  var formEl=document.getElementById("yjc-form");
  formEl.addEventListener("submit", function(e){
    e.preventDefault();
    submitConsult(time);
  });
  /* 고쳐 입력하기 시작하면 이전 오류 문구는 지웁니다 */
  function clearError(){ document.getElementById("yjc-error").textContent=""; }
  formEl.addEventListener("input", clearError);
  formEl.addEventListener("change", clearError);
  document.getElementById("yjc-name").focus();
}

/* 연락 가능한 시간 하나를 고르는 플립시계. 09:00~18:00, 30분 단위.
   처음 값은 지금 시각 이후 가장 가까운 30분(근무시간 밖이면 09:00). */
function installFlipClock(box){
  var now=new Date(), nowMin=now.getHours()*60+now.getMinutes();
  var t=Math.ceil(nowMin/CONSULT_STEP)*CONSULT_STEP;
  if(t<CONSULT_MIN || t>CONSULT_MAX){ t=CONSULT_MIN; }
  var state={ t:t };
  var reduceMotion=window.matchMedia && window.matchMedia("(prefers-reduced-motion: reduce)").matches;
  var up='<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="3" stroke-linecap="round" stroke-linejoin="round"><path d="M6 15l6-6 6 6"/></svg>';
  var dn='<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="3" stroke-linecap="round" stroke-linejoin="round"><path d="M6 9l6 6 6-6"/></svg>';
  function card(p){
    var what=p==="h"?"시":"분";
    return '<div class="flip" id="yjf-'+p+'"><div class="half top"><span></span></div><div class="half bottom"><span></span></div>'
      +'<button type="button" class="yjf-hit up" data-p="'+p+'" data-d="1" aria-label="'+what+' 올리기">'+up+'</button>'
      +'<button type="button" class="yjf-hit dn" data-p="'+p+'" data-d="-1" aria-label="'+what+' 내리기">'+dn+'</button></div>';
  }
  box.innerHTML='<div class="yjf-row">'+card("h")+'<span class="yjf-colon" aria-hidden="true">:</span>'+card("m")+'</div>'
    +'<div class="yjf-sum" id="yjf-sum" aria-live="polite"></div>'
    +'<div class="yjf-help">숫자 위쪽을 누르면 올라가고, 아래쪽을 누르면 내려가요<br>상담 가능 시간 09:00~18:00</div>'
    +'<div class="yjf-notice">최대한 원하시는 시간에 연락드리겠지만, 약간의 오차가 발생할 수 있습니다.</div>';

  function setCard(el, val, animate){
    var old=el.getAttribute("data-v");
    el.setAttribute("data-v", val);
    var top=el.querySelector(".top span"), bottom=el.querySelector(".bottom span");
    if(!animate || reduceMotion || old===null || old===val){ top.textContent=val; bottom.textContent=val; return; }
    [].forEach.call(el.querySelectorAll(".flap-top,.flap-bottom"), function(f){ f.parentNode.removeChild(f); });
    top.textContent=val;
    var ft=document.createElement("div"); ft.className="half top flap-top"; ft.innerHTML="<span>"+old+"</span>";
    var fb=document.createElement("div"); fb.className="half bottom flap-bottom"; fb.innerHTML="<span>"+val+"</span>";
    el.insertBefore(ft, el.querySelector(".yjf-hit"));
    el.insertBefore(fb, el.querySelector(".yjf-hit"));
    fb.addEventListener("animationend", function(){
      bottom.textContent=el.getAttribute("data-v");
      if(ft.parentNode) ft.parentNode.removeChild(ft);
      if(fb.parentNode) fb.parentNode.removeChild(fb);
    });
  }
  /* 한 칸 움직였을 때의 값. 범위를 벗어나면 null(그 방향 표시가 사라짐).
     시: 1시간씩, 17:30에서 올리면 18:00처럼 끝에 걸리면 끝값으로. 분: 00↔30. */
  function next(p, dir){
    var v;
    if(p==="h"){
      v=state.t+dir*60;
      if(v>CONSULT_MAX && state.t<CONSULT_MAX) v=CONSULT_MAX;
      if(v<CONSULT_MIN && state.t>CONSULT_MIN) v=CONSULT_MIN;
    } else {
      v=Math.floor(state.t/60)*60+(state.t%60===0?30:0);
    }
    return (v<CONSULT_MIN || v>CONSULT_MAX || v===state.t) ? null : v;
  }
  function draw(animate){
    setCard(document.getElementById("yjf-h"), two(Math.floor(state.t/60)), animate);
    setCard(document.getElementById("yjf-m"), two(state.t%60), animate);
    [].forEach.call(box.querySelectorAll(".yjf-hit"), function(b){
      b.disabled = next(b.getAttribute("data-p"), +b.getAttribute("data-d"))===null;
    });
    document.getElementById("yjf-sum").innerHTML='<b>'+hhmm(state.t)+'</b>쯤 연락주세요';
  }
  [].forEach.call(box.querySelectorAll(".yjf-hit"), function(b){
    b.addEventListener("click", function(){
      var v=next(b.getAttribute("data-p"), +b.getAttribute("data-d"));
      if(v===null) return;
      state.t=v;
      draw(true);
    });
  });
  draw(false);
  return state;
}

function submitConsult(time){
  var name=document.getElementById("yjc-name").value.trim();
  var phone=document.getElementById("yjc-phone").value.trim();
  var msg=document.getElementById("yjc-msg").value.trim();
  var agree=document.getElementById("yjc-agree").checked;
  var errEl=document.getElementById("yjc-error");
  var btn=document.getElementById("yjc-submit");
  var c=DATA.contact||{};
  var phoneHint=c.phone?" 급하시면 "+c.phone+"로 전화 주세요.":"";
  errEl.textContent="";
  if(!name || !phone){ errEl.textContent="이름과 연락처를 입력해주세요."; return; }
  if(phone.replace(/[^0-9]/g,"").length<9){ errEl.textContent="연락처를 다시 확인해주세요."; return; }
  if(!agree){ errEl.textContent="개인정보 수집·이용에 동의해주세요."; return; }

  var info=consultInfo;
  var when=hhmm(time.t);
  /* 광고·검색 등 어디서 들어온 사람인지 (js/main.js). 개인정보는 들어있지 않습니다. */
  var attr=(window.YJ_ATTR && window.YJ_ATTR.get()) || {};
  btn.disabled=true;
  btn.textContent="보내는 중...";
  fetch("api/contact.php", {
    method:"POST",
    headers:{ "Content-Type":"application/json" },
    body:JSON.stringify({ action:"consult", name:name, phone:phone, message:msg,
      courseCode:info.code, courseTitle:info.title, coursePath:info.path, estTotal:info.total||"", contactTime:when,
      attrSrc:attr.src||"", attrKw:attr.kw||"" })
  }).then(function(r){
      return r.json().catch(function(){ return {}; }).then(function(d){ return { ok:r.ok, data:d }; });
    })
    .then(function(res){
      if(!res.ok){
        var e=new Error((res.data && res.data.error) || "접수에 실패했습니다.");
        e.fromServer=true;
        throw e;
      }
      document.getElementById("yjc-slot").innerHTML='<div class="yjc-done" role="status"><strong>상담 신청이 접수됐어요</strong>'
        +esc(name)+'님, <b>'+esc(info.title)+'</b> 과정으로 신청해주셨어요.<br><b>'+when+'</b>쯤 입력하신 번호로 연락드릴게요.'
        +'<small>최대한 원하시는 시간에 연락드리겠지만, 약간의 오차가 발생할 수 있습니다.</small></div>';
    })
    .catch(function(err){
      /* 서버가 거절한 경우엔 그 이유를, fetch 자체가 실패(오프라인 등)하면 연결 안내를 보여줍니다 */
      var text=(err && err.fromServer) ? err.message
        : (err instanceof TypeError ? "인터넷 연결을 확인해주세요." : "접수에 실패했습니다.");
      errEl.textContent=text+phoneHint;
      btn.disabled=false;
      btn.textContent="상담 신청 보내기";
    });
}

function renderCrumbs(){
  var el=document.getElementById("yjlg-crumbs");
  if(!el) return;
  if(!history.length){ el.innerHTML=""; return; }
  var parts=history.map(function(h){ return '<span class="yjlg-crumb">'+h.opt.t+'</span>'; });
  el.innerHTML=parts.join('<span class="yjlg-crumb-sep">›</span>');
}

var VEHICLE_ICONS={
  B2:'<path d="M5 11 6.5 6a2 2 0 0 1 2-1.5h7a2 2 0 0 1 2 1.5L19 11"/><rect x="2.5" y="11" width="19" height="6" rx="2"/><circle cx="7" cy="17" r="1.8"/><circle cx="17" cy="17" r="1.8"/>',
  B1:'<path d="M4 11 5 7a2 2 0 0 1 2-1.5h10a2 2 0 0 1 2 1.5l1 4"/><rect x="2" y="11" width="20" height="6.5" rx="2"/><circle cx="7" cy="17.5" r="1.8"/><circle cx="17" cy="17.5" r="1.8"/>',
  S2:'<circle cx="5.5" cy="17.5" r="3.2"/><circle cx="18.5" cy="17.5" r="3.2"/><path d="M8.5 17.5h6l3-6h3M11 11.5 13 6h3"/>',
  WD:'<circle cx="5" cy="18" r="2.4"/><circle cx="17" cy="18" r="2.4"/><path d="M7 18h6l1.5-5h3.5M12 18l2-8h2"/>',
  DH:'<rect x="1" y="7" width="20" height="9" rx="1.5"/><path d="M1 11h20"/><path d="M4.5 7v4M18 7v4"/><circle cx="5.5" cy="18.5" r="1.8"/><circle cx="16.5" cy="18.5" r="1.8"/>',
  SG:'<path d="M1 12 2 9a1.5 1.5 0 0 1 1.5-1h3a1.5 1.5 0 0 1 1.5 1L9 12"/><rect x="0.5" y="12" width="10" height="5" rx="1.5"/><circle cx="3" cy="18" r="1.6"/><circle cx="8" cy="18" r="1.6"/><path d="M11 15h2"/><rect x="14" y="12" width="9" height="5" rx="2.2"/><circle cx="17" cy="18" r="1.4"/><circle cx="21" cy="18" r="1.4"/><path d="M17.5 14.2h2.4"/>',
  DG:'<rect x="1" y="10" width="7" height="7" rx="1"/><circle cx="3.5" cy="18.5" r="1.6"/><circle cx="6.5" cy="18.5" r="1.6"/><path d="M8 13h1.5"/><rect x="10" y="9" width="12" height="8" rx="1"/><circle cx="13" cy="18.5" r="1.6"/><circle cx="19" cy="18.5" r="1.6"/>'
};
var VEHICLE_COND={
  B2:"승용차·10인승 이하 승합차 운전 가능",
  B1:"승용차·15인승 이하 승합차 운전 가능",
  S2:"125cc 초과 이륜차 운전 가능",
  WD:"125cc 이하 이륜차 운전 가능",
  DH:"대형버스·대형화물차 운전 가능",
  SG:"카라반 등 소형 트레일러 견인 가능",
  DG:"대형 트레일러(10톤 초과) 견인 가능"
};
function vehicleIcon(key){
  var inner=VEHICLE_ICONS[key];
  if(!inner) return "";
  return '<svg class="yjlg-opt-icon" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round">'+inner+'</svg>';
}

function renderQuestion(nodeId){
  var node=NODES[nodeId];
  var html="";
  if(history.length){ html+='<button class="yjlg-backlink" onclick="__yjwiz.back()">'+svgBack()+' 이전으로</button>'; }
  html+='<h2 class="yjlg-question">'+node.q+'</h2>';

  if(nodeId==="ROOT" && window.YJLG_ROOT_MODE==="images"){
    html+='<p class="yjlg-mode-switch">면허 이름을 이미 알고 계세요? <a href="license-guide.html">텍스트로 찾기</a></p>';
    html+='<div class="yjlg-tiles">';
    node.opts.forEach(function(opt,i){
      var cond=opt.go?VEHICLE_COND[opt.go]:"";
      var img=opt.go?("images/license-tiles/tile-"+opt.go.toLowerCase()+".png"):"";
      html+='<button class="yjlg-tile" onclick="__yjwiz.choose('+i+')">'
        +(img?'<img class="yjlg-tile-img" src="'+img+'" alt="" width="120" height="120">':'')
        +'<span class="yjlg-tile-title">'+opt.t+'</span>'
        +(cond?'<span class="yjlg-tile-cond">'+cond+'</span>':'')
        +'</button>';
    });
    html+='</div>';
  } else {
    if(nodeId==="ROOT"){
      html+='<p class="yjlg-mode-switch">그림으로 편하게 고르고 싶으세요? <a href="license-picker.html">이미지로 찾기</a></p>';
    }
    html+='<div class="yjlg-options">';
    node.opts.forEach(function(opt,i){
      var cond=opt.go?VEHICLE_COND[opt.go]:null;
      html+='<button class="yjlg-opt" onclick="__yjwiz.choose('+i+')"><span class="yjlg-opt-left">'+(opt.go?vehicleIcon(opt.go):"")+'<span class="yjlg-opt-text"><span class="yjlg-opt-title">'+opt.t+'</span>'+(cond?'<span class="yjlg-opt-cond">'+cond+'</span>':"")+'</span></span>'+svgChevron()+'</button>';
    });
    html+='</div>';
  }

  var card=document.getElementById("yjlg-card");
  card.innerHTML=html;
  card.classList.remove("yjlg-animate"); void card.offsetWidth; card.classList.add("yjlg-animate");
  window.__yjwizOpts=node.opts;
}

function renderResult(){
  var card=document.getElementById("yjlg-card");
  var html='<button class="yjlg-backlink" onclick="__yjwiz.back()">'+svgBack()+' 이전으로</button>';

  if(terminal.type==="blocked"){
    html+='<span class="yjlg-pill yjlg-danger">'+STATUS_LABEL.blocked.text+'</span>';
    html+='<div class="yjlg-result-title">지금은 조건이 맞지 않아요</div>';
    html+='<div class="yjlg-callout yjlg-danger">'+terminal.msg+'</div>';
    html+=contactBox();
    html+='<button class="yjlg-reset-btn" onclick="__yjwiz.reset()">'+svgReset()+' 처음부터 다시 선택하기</button>';
    card.innerHTML=html;
    card.classList.remove("yjlg-animate"); void card.offsetWidth; card.classList.add("yjlg-animate");
    return;
  }

  var code=terminal.code;
  var c=DATA.cases[code];
  if(!c){
    html+='<span class="yjlg-pill yjlg-warn">상담 필요</span><div class="yjlg-result-title">아직 등록되지 않은 조합이에요</div>';
    html+='<div class="yjlg-callout yjlg-warn">이 조합은 아직 기준이 등록되지 않았어요. 학원에 문의해주시면 정확히 안내해드릴게요.</div>';
    html+=contactBox();
    html+='<button class="yjlg-reset-btn" onclick="__yjwiz.reset()">'+svgReset()+' 처음부터 다시 선택하기</button>';
    card.innerHTML=html;
    return;
  }
  var sl=STATUS_LABEL[c.status]||STATUS_LABEL.warn;

  html+='<div class="yjlg-code-tag">코드 '+code+'</div>';
  html+='<span class="yjlg-pill '+sl.cls+'">'+sl.text+'</span>';
  var titleText=c.target+(terminal.cond?' ('+terminal.cond+')':'');
  html+='<div class="yjlg-result-title">'+esc(titleText)+'</div>';
  html+='<p class="yjlg-result-sub">선택하신 조건 기준 예상 결과예요</p>';
  consultInfo={ code:code, title:titleText, total:"",
    path:history.map(function(h){ return h.opt.t; }).join(" › ") };

  if(c.status!=="doc" && c.status!=="unknown" && c.hours){ html+=renderFlow(c); }

  if(c.status==="doc"){
    html+='<div class="yjlg-callout yjlg-info">'+esc(c.note)+'</div>'+consultSlot()+contactBox();
  } else if(c.status==="unknown"){
    html+='<div class="yjlg-callout yjlg-warn">'+esc(c.note)+'</div>'+consultSlot()+contactBox();
  } else {
    html+='<div class="yjlg-section-label">필요한 교육시간</div><div class="yjlg-stat-grid">';
    ["hak","gi","doro"].forEach(function(k){
      var label=k==="hak"?"학과":(k==="gi"?"장내기능":"도로주행");
      var cell=hourCell(c.hours[k]);
      html+='<div class="yjlg-stat"><div class="yjlg-k">'+label+'</div><div class="yjlg-v'+(cell.dim?" yjlg-dim":"")+'">'+cell.text+'</div></div>';
    });
    html+='</div>';

    html+='<div class="yjlg-section-label">예상 수강료</div><div class="yjlg-stat-grid">';
    ["hak","gi","doro"].forEach(function(k){
      var label=k==="hak"?"학과":(k==="gi"?"장내기능":"도로주행");
      var cell=costCell(c.cost[k]);
      html+='<div class="yjlg-stat"><div class="yjlg-k">'+label+'</div><div class="yjlg-v'+(cell.dim?" yjlg-dim":"")+'">'+cell.text+'</div></div>';
    });
    html+='</div>';

    /* 해당 교육시간이 0시간(이미 이수/면제)이면 그 항목의 시험(검정)도 면제로 봅니다.
       예: 기능교육 0시간인 케이스는 장내기능검정도 이미 통과했거나 면제된 경우예요. */
    var examNums=examFeeNumbers(code);
    var giHours=c.hours.gi?c.hours.gi[0]:null;
    var doroHours=c.hours.doro?c.hours.doro[0]:null;
    var effExamGi=(typeof examNums.examGi==="number" && giHours>0)?examNums.examGi:null;
    var effExamDoro=(typeof examNums.examDoro==="number" && doroHours>0)?examNums.examDoro:null;
    var hasExam=(effExamGi!==null)||(effExamDoro!==null);
    if(hasExam){
      html+='<div class="yjlg-section-label">시험 응시료(검정료)</div><div class="yjlg-stat-grid">';
      if(effExamGi!==null){
        html+='<div class="yjlg-stat"><div class="yjlg-k">장내검정료</div><div class="yjlg-v">'+won(effExamGi)+'</div></div>';
      }
      if(effExamDoro!==null){
        html+='<div class="yjlg-stat"><div class="yjlg-k">도로주행검정료</div><div class="yjlg-v">'+won(effExamDoro)+'</div></div>';
      }
      html+='</div>';
    }

    var total=calcTotalWithExam(c.cost, effExamGi, effExamDoro);
    html+='<div class="yjlg-total-tile"><span class="yjlg-k">예상 합계</span><span class="yjlg-v">'+(total==="unknown"?"문의 필요":won(total))+'</span></div>';
    html+='<p class="yjlg-fineprint">'+(total==="unknown"?"":"정확한 금액은 상담 시 확인해드려요. 상기 수강료는 의무교육을 기준으로 산정되었으며, 시험은 각 1회씩 포함된 가격입니다.")+'</p>';
    consultInfo.total=(total==="unknown"?"문의 필요":won(total));
    html+=consultSlot();

    if(c.note){ html+='<div class="yjlg-callout '+(c.status==="warn"?"yjlg-warn":"yjlg-ok")+'">'+esc(c.note)+'</div>'; }

    html+=estimateDuration(c);
    html+=renderScheduleBlock(code, c);
    html+=renderPrepBlock();
    html+=renderExamScheduleBlock(effExamGi, effExamDoro);

    if(c.status==="warn"){ html+=contactBox(); }
  }

  html+='<button class="yjlg-reset-btn" onclick="__yjwiz.reset()">'+svgReset()+' 처음부터 다시 선택하기</button>';
  card.innerHTML=html;
  card.classList.remove("yjlg-animate"); void card.offsetWidth; card.classList.add("yjlg-animate");
}

function render(){
  var c=DATA.contact||{};
  root.innerHTML=""
    +'<div class="yjlg-shell">'
      +'<div class="yjlg-brand"><div class="yjlg-id">'+svgCar()+'<span><b>'+esc(c.name||"")+'</b> · '+(window.YJLG_ROOT_MODE==="images"?"이미지로 면허 찾기":"면허 가이드")+'</span></div></div>'
      +'<header class="yjlg-hero"><h1>면허, 선택만 하세요. 나머지는 저희가 찾아드릴게요.</h1>'
      +'<p>지금 갖고 계신 면허와 새로 따고 싶은 면허를 하나씩 골라주시면, 필요한 교육시간과 예상 수강료를 바로 계산해드려요.</p></header>'
      +'<div class="yjlg-crumbs" id="yjlg-crumbs"></div>'
      +'<main class="yjlg-card yjlg-animate" id="yjlg-card"></main>'
      +'<footer><span class="yjlg-school">'+esc(c.name||"")+'</span><br/>'+esc(c.address||"")+' · 전화 '+esc(c.phone||"")+'<br/>'
      +'안내된 시간·비용은 참고용이며, 실제 등록 시 최신 기준으로 다시 확인해드려요.'
      +(DATA.updatedAt? '<br/>최근 업데이트: '+esc(DATA.updatedAt) : '')+'</footer>'
    +'</div>';
  window.__yjwiz={ choose:function(i){ choose(window.__yjwizOpts[i]); }, back:goBack, reset:resetAll, consult:openConsult };
  renderCrumbs();
  if(terminal){ renderResult(); } else { renderQuestion(currentNodeId()); }
}

function init(){
  root=document.getElementById("yj-license-guide");
  if(!root){ return; }
  if(!document.getElementById("yjlg-fonts")){
    var fontLink=document.createElement("link");
    fontLink.id="yjlg-fonts";
    fontLink.rel="stylesheet";
    fontLink.href="https://fonts.googleapis.com/css2?family=Gothic+A1:wght@500;700;800&family=Noto+Sans+KR:wght@400;500;700&family=JetBrains+Mono:wght@500;700&display=swap";
    document.head.appendChild(fontLink);
  }
  ensureConsultAssets();
  render();
}

function startWithFreshData(){
  fetch("api/license-data.php").then(function(r){ return r.ok ? r.json() : null; }).then(function(res){
    if(res && res.data){ DATA = res.data; }
  }).catch(function(){}).then(function(){
    if(document.readyState==="loading"){ document.addEventListener("DOMContentLoaded", init); } else { init(); }
  });
}
startWithFreshData();

})();
