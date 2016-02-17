<!DOCTYPE html PUBLIC "-//W3C//DTD XHTML 1.0 Strict//EN" "http://www.w3.org/TR/xhtml1/DTD/xhtml1-strict.dtd">
<html xmlns="http://www.w3.org/1999/xhtml" xml:lang="en" lang="en">
<head>
  <meta http-equiv="content-type" content="text/html; charset=utf-8" />
  <script type="text/javascript" src="http://code.jquery.com/jquery-latest.min.js"></script>
  <script src="http://code.highcharts.com/highcharts.js"></script>
  <script src="https://code.highcharts.com/modules/heatmap.js"></script>
  <script src="https://code.highcharts.com/highcharts-more.js"></script>
  <script src="http://code.highcharts.com/modules/exporting.js"></script>
  <script src="http://highcharts.github.io/export-csv/export-csv.js"></script>

  <title>NWS JS Graphing Example</title>
</head>
<body>
  <h1>USGS Water Services AJAX Example</h1>
  <table>
    <tr>
          <td>
        <table width="100%" border="0">
              <tr>
                   <td><label for="site"><strong>NWS Id No. </strong></label><input type="text" id="site" value ="lirv2" size="8" maxlength="15" /></td>
               </tr>
               <tr>
                  <td><label for="watershedArea"><strong>Area </strong></label><input id="watershedArea" size="7" readonly="readonly" />mi<sup>2</sup></td>
                </tr>
            </table>
      </td>
  </tr>
  </table>
  <form id="form1" method="post" action="">

      <p>
            <input type="button" name="query" id="query" value="Get Latest Streamflow" />
      </p>
  </form>


<table>
  <tr>
        <td>
          <input type="checkbox" id="showFloodStages" checked>Show Flood Stages<br>
          <div id="stagegraph" style="width: 700px; height: 550px; margin: 0 auto"></div>
        </td>
        <td>
          <input type="checkbox" id="dischargeAxis" style="padding-left:20px" checked>Log Scale<br>
          <div id="dischargegraph" style="width: 500px; height: 500px; padding-left:20px"></div>
    </td>
  </tr>
    <tr>
        <td>
          <div id="heatMapQ" style="width: 700px; height: 550px; margin: 0 auto"></div>
        </td>
        <td>
          <input type="checkbox" id="ratingLog" style="padding-left:20px" >Log Scale<br>
          <div id="ratinggraph" style="width: 500px; height: 550px; margin: 0 auto"></div>
        </td>
    </tr>

</table>
<div id='stats'></div>
      <p>
            <input type="button"  id="heatQ" value="Create Daily Qmap" />
      </p>

<script type="text/javascript">

function addStats(chart,USGSsite,ext){
  console.log(ext);
  console.log(ext.max);
  $.get('http://waterservices.usgs.gov/nwis/stat/?format=rdb,1.0&indent=on&sites='+USGSsite+'&statReportType=daily&statTypeCd=min,max,median,p05,p25,p75,p95&parameterCd=00060', function( data ) {
    var lines = data.split('\n');
    var statsSeries = [
      {
        name: 'Min-Max Flows',
        data: [],
        visible : false,
        type: 'arearange',
        lineWidth: 0,
        color: '#00ffff',
        fillOpacity: 0.1,
        zIndex: 0,
        showInLegend:true
      },
      {
        name: 'P 5% -  95%',
        data: [],
        visible : false,
        type: 'arearange',
        lineWidth: 0,
        linkedTo: ':previous',
        color: '#00bfff',
        fillOpacity: 0.2,
        zIndex: 1
      },
      {
        name: 'P 25% - 75%',
        data: [],
        visible : false,
        type: 'arearange',
        lineWidth: 0,
        linkedTo: ':previous',
        color: '#0040ff',
        fillOpacity: 0.3,
        zIndex: 2
      },
      {
        name: 'P 50% (median)',
        linkedTo: ':previous',
        showInLegend:false,
        color: 'grey',
        data: []
      },
      {
        name: 'Count',
        data: [],
        showInLegend:false,
        visible : false
      },
      {
        name: 'Min Year',
        data: [],
        showInLegend:false,
        visible : false
      },
      {
        name: 'Max year',
        data: [],
        showInLegend:false,
        visible : false
      }];

    $.each(lines, function(){
      if (this.substring(0, 4) == "USGS") {
        var data = this.split('\t');
        var month = data[5];
        var day = data[6];
        var year = '2016';
        var date = Date.UTC(year,month,day);
        statsSeries[0].data.push([date,parseInt(data[13]),parseInt(data[11])]);
        statsSeries[1].data.push([date,parseInt(data[14]),parseInt(data[18])]);
        statsSeries[2].data.push([date,parseInt(data[15]),parseInt(data[17])]);
        statsSeries[3].data.push([date,parseInt(data[16])]);
        statsSeries[4].data.push([date,parseInt(data[9])]);
        statsSeries[5].data.push([date,parseInt(data[12])]);
        statsSeries[6].data.push([date,parseInt(data[10])]);
      }

    });

    $.each(statsSeries,function(){
      this.marker = { enabled:false}
      chart.addSeries(this);
      console.log(this);
    });
  });
}

//Global Vars
var usgsSite = '';
var nwsLid = '';
var USGS;
var chart;
var hasNWSBands = true;
var crossWalkTable;
var NWS;

function initializeNWS() {
  NWS = {
            stage :{
              low : {
                text: 'Low Flow Stage',
                   value: 9999,
                   def:''
                 },
              bankfull : {
                text: 'Bankfull Stage',
                   value: 9999
                 },
                 action : {
                text: 'Action Stage',
                   value: 9999,
                   def:'A stream, lake or reservoir has rised to a level where you should prepare for possible significant flooding'
                 },
                 flood : {
                text: 'Flood Stage',
                   value: 9999,
                   def:'Minimal or no property damage, but possible some public threat'
                 },
               moderate : {
                text: 'Moderate Flood Stage',
                   value: 9999,
                   def: 'Some inundation of structures and roads near streams. Some evacuations of people and/or transfer of property to higher elevations are necessary'
                 },
               major : {
                text: 'Major Flood Stage',
                   value: 9999,
                   def:'Extensive inundation of structures and roads. Significant evacuations of people and/or transfer of property to higher elevations'
                 },
                 record: {
                text: 'Record Stage',
                   value: 9999,
                   def:'Highest stage at a given site during the period of record'
                 }
             },
             maxY : -1,
             nwsTitle: '',
             usgsTitle:'',
             forecastStage: [],
             observedStage: [],
             forecastDischarge: [],
             observedDischarge: [],
             sources:[],
             stageSeries: [],
             dailyUSGS: [],
             ratingsSeries:[],
             dischargeSeries: [],
             forecastIssued : ''
  }

}



// var jqxhr = $.getJSON( "crossWalk.json", function() {
//   console.log( "success" );
// })
//   .done(function() {
//     console.log( "second success" );
//   })
//   .fail(function() {
//     console.log( "error" );
//   })
//   .always(function() {
//     console.log( "complete" );
//   });

$.ajax({
    type: 'GET',
      url: 'crossWalk.json',
      dataType: 'json',
      success: function(data) {
        crossWalkTable = data;
        usgsSite = crossWalkTable[nwsLid];
        },
      async: true,
      error : function(XMLHttpRequest, textStatus, errorThrown) {
            alert('error');

        }
});


function getRDBSiteInfo(RDB,values){
      var data = { }

      var extractVals = new Array()
    var lines = RDB.split('\n');

    //Iterate through the comment lines of the RDB file
    var j = 0
    for(j = 0;j < lines.ƒlength;j++){
      if(lines[j].charAt(0) != '#') break
    }
    var cols = lines[j].split('\t')
    j = j+2
    for(var i=0;i<values.length;i++){
      var index = cols.indexOf(values[i]);

      extractVals.push(index)
    }
    var siteInfo = lines[j].split('\t')
    for(var i=0;i<extractVals.length;i++){
      data[values[i]] = siteInfo[extractVals[i]]
    }
    return data
  }

function GetObjectKeyIndex(obj, keyToFind) {
    var i = 0, key;
    for (key in obj) {
        if (key == keyToFind) {
            return i;
        }
        i++;
    }
    return null;
}

function ratingJSON2HC(json){
    var indepIndex = GetObjectKeyIndex(json.columns,"INDEP");
    var depIndex = GetObjectKeyIndex(json.columns,"DEP");
    var series = {};

    len = json.records.length;

    if(len == 0) return data = null;

    var data = new Array();
    for (var i = 0; i < len; i++) {
        data.push([json.records[i][depIndex],json.records[i][indepIndex]]);
    }
    series['data'] = data;

    series['name'] = 'USGS Rating Curve';
    series['zIndex'] = 9;
    series['tooltip']= {
                headerFormat: 'USGS Rating Curve<br>',
                pointFormat: 'Gage Height: {point.y} ft Discharge: {point.x} cfs  </b>'
    };
    return series;
}

function sortFunction(a, b) {
    if (a.x === b.x ){
        return 0;
    }
    else {
        return (a.x < b.x) ? -1 : 1;
    }
}

function sortNinthCol(a, b) {
    if (a[9] === b[9] ){
        return 0;
    }
    else {
        return (a[9] < b[9]) ? -1 : 1;
    }
}

function numberWithCommas(x) {
    return x.toString().replace(/\B(?=(\d{3})+(?!\d))/g, ",");
}

function dateFromDay(year, day){
  var date = new Date(year, 0); // initialize a date in `year-01-01`
  return new Date(date.setDate(day)); // add the number of days
}


function _calculateAge(birthday) { // birthday is a date
    var ageDifMs = Date.now() - birthday.getTime();
    var ageDate = new Date(ageDifMs); // miliseconds from epoch
    return Math.abs(ageDate.getUTCFullYear() - 1970);
}

function qMeasJSON2HC(json){
    var indepIndex = GetObjectKeyIndex(json.columns,"discharge_va");
    var depIndex = GetObjectKeyIndex(json.columns,"gage_height_va");
    var dtIndex = GetObjectKeyIndex(json.columns,"measurement_dt");
    var tzIndex = GetObjectKeyIndex(json.columns,"tz_cd");


    var base = {
            data: [],
           tooltip: {
                headerFormat: 'USGS Measured Discharge<br>',
                pointFormat: '{point.name} <br> Gage Height:{point.y} ft Discharge:{point.x} cfs  </b>'
            },
            name: 'USGS Measured',
            zIndex : 1,
            marker : {
                enabled : true,
                fillColor:'red',
                symbol: 'diamond'
            },
            lineWidth : 0,
            states:{
                hover:{
                    enabled : true,
                    lineWidth:0,
                    lineWidthPlus:0
                }
             }
        }

    var allSeries = [base];
    allSeries.push(jQuery.extend(true, {}, base));
    allSeries.push(jQuery.extend(true, {}, base));
    allSeries.push(jQuery.extend(true, {}, base));
    allSeries[0].zIndex = 5;
    allSeries[1].zIndex = 4;
    allSeries[2].zIndex = 3;
    allSeries[3].zIndex = 2;
    allSeries[1].linkedTo = ':previous';
    allSeries[2].linkedTo = ':previous';
    allSeries[3].linkedTo = ':previous';
    allSeries[0].marker.fillColor = 'black';
    allSeries[1].marker.fillColor = 'rgba(0,0,205,0.7)';
    allSeries[2].marker.fillColor = 'rgba(0,0,205,0.3)';
    allSeries[3].marker.fillColor = 'rgba(0,0,205,0.1)';

    json.records = json.records.sort(sortNinthCol);
    len = json.records.length;

    if(len == 0) return data = null;



    var data = new Array();
    for (var i = 0; i < len; i++) {
        date = new Date(json.records[i][dtIndex]);
        age = _calculateAge(date);
        var index = 3;
        if(age < 10) index =2 ;
        if(age < 5) index = 1;
        if(age < 1 ) index = 0;
        if(isFinite(String(json.records[i][depIndex]))){
            allSeries[index].data.push( {'x' : json.records[i][indepIndex],
                'y': json.records[i][depIndex],
                'name': date.toDateString()
            });
        }
    }

    return allSeries;
}


function USGStoHC(usgsObj,TSvalue){
    len = usgsObj.value.timeSeries.length;
    if(len == 0) return data = null;
    data = new Array();
    QRarr = 0;
    for (var i = 0; i < len; i++) {
      if (usgsObj.value.timeSeries[i].variable.variableCode[0].value == TSvalue){
        QRarr = i;
        break;
      }
    }

    numQ = usgsObj.value.timeSeries[QRarr].values[0].value.length;
    for (var i = 0; i < numQ; i++) {
      d = new Date(usgsObj.value.timeSeries[QRarr].values[0].value[i].dateTime).getTime();
      val = usgsObj.value.timeSeries[QRarr].values[0].value[i].value;
      val = parseFloat(val);
      if(val < -999) val  = null;
      data.push([parseInt(d),val])
    }
    return data;
}

function USGSDailytoHCHeat(usgsObj,TSvalue){
    len = usgsObj.value.timeSeries.length;
    if(len == 0) return data = null;
    data = new Array()
    QRarr = 0;
    for (var i = 0; i < len; i++) {
      if (usgsObj.value.timeSeries[i].variable.variableCode[0].value == TSvalue){
        QRarr = i;
        break;
      }
    }

    numQ = usgsObj.value.timeSeries[QRarr].values[0].value.length;
    for (var i = 0; i < numQ; i++) {
      var d = new Date(usgsObj.value.timeSeries[QRarr].values[0].value[i].dateTime);

      var year = d.getFullYear();
      var start = new Date(year,0,0);
      var diff = d - start;
      var oneDay = 1000 * 60 * 60 * 24;
      var day = Math.floor(diff / oneDay);
      val = usgsObj.value.timeSeries[QRarr].values[0].value[i].value
      //val = Math.log(parseFloat(val));
      val = parseFloat(val);
      if(val < -999) val  = null;
      data.push([parseInt(day),parseInt(year),val])
    }
    return data
}


/**
 * Add the NWS flood level bands to the chart
 *
 */
function addNWSBands(chart,NWSobj){

  extY = chart.yAxis[0].getExtremes()
  legW = 550
  col1Width = 170
  legendy = chart.yAxis[0].toPixels(extY.min)+70
  legTextHeight = 10
  cellPadding = 1
  legendx = 110
  upperEnd = 9999
  var colors = {
    major : 'rgba(204,51,255,0.7)',
    moderate : 'rgba(255,0,0,0.7)',
    flood : 'rgba(255,153,0,0.7)',
    action : 'rgba(255,255,0,0.7)',
  }

  $.each( colors, function( key, value ){
      if(NWSobj.stage[key].value < 9999){
      chart.yAxis[0].addPlotBand({
        from: NWSobj.stage[key].value,
        to: upperEnd,
        color: value,
        id : 'plotBand'+key,
        zIndex:0
      })
      chart.yAxis[0].addPlotLine({
        color: 'black',
        width: 1,
        zIndex:6,
        id: 'plotLine'+key,
        value: NWSobj.stage[key].value,
        label: {
          text: NWSobj.stage[key].text.split(/\s+/)[0]+': '+NWSobj.stage[key].value+' ft',
          textAlign: 'left',
          y:-2
        }
      })
      upperEnd = NWSobj.stage[key].value
      text = chart.renderer.text(NWSobj.stage[key].text,legendx,legendy)
        .attr({
          zIndex:6,
          class:'nwsLegend'})
        .css({
          color: 'black',
                    'text-align': 'center',
                     'font-weight': 'bold',
                    width:  col1Width,
                    fontSize: legTextHeight+'px'
              }).add();
            text = chart.renderer.text(NWSobj.stage[key].value+' ft',legendx+col1Width-40,legendy)
        .attr({
          zIndex:6,
          class:'nwsLegend'})
        .css({
          color: 'black',
                    'text-align': 'center',
                     'font-weight': 'bold',
                    width:  col1Width,
                    fontSize: legTextHeight+'px'
              }).add();
      text = chart.renderer.text(NWSobj.stage[key].def,legendx+col1Width,legendy)
        .attr({
          zIndex:6,
          class:'nwsLegend'})
        .css({
          color: 'black',
                    'text-align': 'center',
                    width:  legW-col1Width,
                    fontSize: legTextHeight+'px'
              }).add();

      chart.renderer.rect(legendx - 5,legendy-legTextHeight-cellPadding, legW+5, 2*legTextHeight+5*cellPadding, 0)
              .attr({
                  fill: value,
                  stroke: value,
              class: 'nwsLegend',
                  zIndex: 4
              }).add();
              legendy = legendy+2*legTextHeight+5*cellPadding


    }
  });

    if(NWSobj.stage.record.value < 9999){
      chart.yAxis[0].addPlotLine({
                color: '#99FFFF',
                width: 2,
                zIndex:6,
                value: NWSobj.stage.record.value,
                dashStyle: 'longDash',
                id : 'plotLineRecord',
                label: {
                    text: 'NWS Record Stage'+': '+NWSobj.stage.record.value+' ft',
                    align: 'center',
                    style: {
                      color: 'black',
                    }
                }
            })
        }


}



function addNWSRender(chart){

    nowP = chart.xAxis[0].toPixels(new Date().getTime())
    extX = chart.xAxis[0].getExtremes();
    extY = chart.yAxis[0].getExtremes();
    xmaxP = chart.xAxis[0].toPixels(extX.max)
    xminP = chart.xAxis[0].toPixels(extX.min)
    ymaxP = chart.yAxis[0].toPixels(extY.max)
    chart.renderer.path(['M', xminP+10, ymaxP-10, 'L', nowP-10, ymaxP-10])
      .attr({
                'stroke-width': 3,
                stroke: 'grey',
                class: 'nwsRender',
                zIndex: 3
            }).add()
    chart.renderer.path(['M',nowP+10,ymaxP-10,'L',xmaxP-10,ymaxP-10])
      .attr({
                'stroke-width': 3,
                stroke: 'blue',
                class: 'nwsRender',
                zIndex: 3
            }).add()
    text = chart.renderer.text(
                  'Observed',
                  ((nowP+xminP)/2) -30,
                    ymaxP-5
                ).attr({
                  zIndex: 5,
                  class: 'nwsRender'
              }).css({
                    color: 'grey',
                  fontSize: '16px'
              }).add(),
        box = text.getBBox();
        chart.renderer.rect(box.x - 5, box.y, box.width + 10,box.height-3)
            .attr({
                fill: 'white',
                stroke: 'white',
                class: 'nwsRender',
                zIndex: 4
            })
            .add();
        text = chart.renderer.text(
                'Forecast',
                  ((nowP+xmaxP)/2) -30,
                    ymaxP-5
                ).attr({
                  zIndex: 5,
                  class: 'nwsRender'
              }).css({
                    color: 'blue',
                  fontSize: '16px'
              }).add(),
        box = text.getBBox();
        chart.renderer.rect(box.x - 5, box.y, box.width + 10, box.height-3)
            .attr({
                fill: 'white',
                stroke: 'white',
                class: 'nwsRender',
                zIndex: 4
            })
            .add();
        var point;
        if(chart.series[0].data.length>0){
        point = chart.series[0].data[chart.series[0].data.length-1];
      }else if(chart.series[1].data.length>0){
        point = chart.series[1].data[chart.series[1].data.length-1];
      }

    text = chart.renderer.text(
        'Current Level: '+point.y+' ft',
        point.plotX + chart.plotLeft - 130,
        point.plotY + chart.plotTop - 15
      ).css({
        fontSize: '12px',
        fontWeight: 'bold'
      }).attr({
        zIndex: 10,
        class: 'nwsRender'
      }).add();
    text = chart.renderer.text(
        'Latest Observed Value: '+point.y+' ft at '+Highcharts.dateFormat('%l %P %b %e',point.x),
        chart.plotLeft+10,
        chart.plotTop+15
        ).attr({
          zIndex: 10,
          class: 'nwsRender'
        }).css({
          color: 'black',
          width:  '550px',
          fontSize: '12px'
        }).add();
    box = text.getBBox();
     chart.renderer.rect(box.x - 5, box.y - 1, box.width + 10, box.height + 1, 5)
       .attr({
         fill: 'white',
         stroke: 'rgba(255,255,0,1)',
         zIndex: 9,
         class: 'nwsRender'
       }).add();
}



// Proof of concept for AJAX usage with instantaneous values service
$('#showFloodStages').change(function() {
  $(".nwsRender").remove();
  var chart = $('#stagegraph').highcharts();


  if($(this).is(':checked')){
      addNWSBands(chart,NWS);
        hasNWSBands = !hasNWSBands;
        chart.yAxis[0].update({
          tickPositioner: function () {
        var positions = []
        tick = Math.floor(extremes.dataMin)-2
        max = NWS.maxY+2
        increment = 1
        if((max-tick)>20) increment = 2
        for (tick; tick - increment <= max; tick += increment) {
          positions.push(tick);
        }
        return positions
      }
        });
  }else{
    $(".nwsLegend").remove();
    $("#removeLegend").attr('value', 'Add Flood Stages');
    chart.yAxis[0].removePlotBand('plotBandaction');
    chart.yAxis[0].removePlotLine('plotLineaction');
    chart.yAxis[0].removePlotBand('plotBandflood');
    chart.yAxis[0].removePlotLine('plotLineflood');
    chart.yAxis[0].removePlotBand('plotBandmoderate');
    chart.yAxis[0].removePlotLine('plotLinemoderate');
    chart.yAxis[0].removePlotBand('plotBandmajor');
    chart.yAxis[0].removePlotLine('plotLinemajor');
    chart.yAxis[0].removePlotLine('plotLineRecord');
    extremes = chart.yAxis[0].getExtremes();
    chart.yAxis[0].update({
      tickPositioner: function () {
        var positions = []
        tick = Math.floor(extremes.dataMin)-2
        max = extremes.dataMax+2
        increment = 1
        if((max-tick)>20) increment = 2
        for (tick; tick - increment <= max; tick += increment) {
          positions.push(tick);
        }
        return positions
      }
    });
    }
    addNWSRender(chart);

});


$('#heatQ').click(function() {
    plotHeatQ();
    plotRating();
})


function addMeasureQ(){

    var chart = $('#ratinggraph').highcharts();
    $.ajax({
        url: "rdbajax.php?site="+usgsSite+"&type=qmeas",
        dataType: 'json',
        data: '',
        success: function(json){
            var newSeries = qMeasJSON2HC(json);

            for(j = 0;j < newSeries.length;j++){
                if(json.error_info.error == 0){
                    chart.addSeries(newSeries[j]);
                }
            }
        }
    });
}



function plotRating(){
    if($('#site').val().length<= 5){
        nwsLid = $('#site').val().toLowerCase();
        ;
        usgsSite = crossWalkTable[nwsLid];
    }
    else{
        usgsSite= $('#site').val();
    }
    $.ajax({
        url: "rdbajax.php?site="+usgsSite+"&type=rating",
        dataType: 'json',
        data: '',
        success: function(json){
            if(json.error_info.error == 0){
                NWS.ratingsSeries.push(ratingJSON2HC(json));
                graphRating(NWS);
                addMeasureQ();
            }

        },
        async: false
    });
}


function plotHeatQ(){
    if($('#site').val().length<= 5){
        nwsLid = $('#site').val().toLowerCase();
        ;
        usgsSite = crossWalkTable[nwsLid];
    }
    else{
        usgsSite= $('#site').val();
    }
    //Load USGS Rating Curve
    $.ajax({
        url: "http://waterservices.usgs.gov/nwis/dv/?format=json,1.1&indent=on&sites=" + usgsSite + "&parameterCd=00060&startDT=1800-01-01&endDT=2100-01-19",
        dataType: 'json',
        data: '',
        success: function(json){
            if(json.value.timeSeries.length>0){
                NWS.dailyUSGS.push({
                    data: USGSDailytoHCHeat(json,"00060"),
                    name:'USGS Daily Data',
                    zIndex:2,
                    nwsType: 'observed'
                });
                NWS.usgsTitle = json.value.timeSeries[0].sourceInfo.siteName;
                graphHeatQ(NWS);
            }
        },
        error : function(XMLHttpRequest, textStatus, errorThrown) {
            $('#discharge').val('');
            $('#date').val('');
        }
    });

    //console.log(NWS.dailyUSGS);
}



$('#query').click(function() {
   initializeNWS();
   plotHeatQ();
   plotRating();
   if($('#site').val().length<=5){
        nwsLid = $('#site').val().toLowerCase();

        usgsSite = crossWalkTable[nwsLid];
    }
    else{
        usgsSite= $('#site').val();
    }

    USGS = {
      data : [],
      title : ''
    }
    //Load USGS Observed Data
    $.ajax({
      url: "http://waterservices.usgs.gov/nwis/iv/?format=json&sites=" + usgsSite + "&parameterCd=00060,00065&period=P7D",
      dataType: 'json',
      data: '',
      success: function(json){
         if(json.value.timeSeries.length>0){
             NWS.sources.push('USGS');
             NWS.stageSeries.push({
               data : USGStoHC(json,"00065"),
               name : 'USGS Observed Data',
               zIndex: 2,
        nwsType: 'observed'
             });
             NWS.dischargeSeries.push({
        data: USGStoHC(json,"00060"),
        name:'USGS Observed Data',
        zIndex:2,
        nwsType: 'observed'
      });
           NWS.usgsTitle = json.value.timeSeries[0].sourceInfo.siteName

     }
       },
      async: false,
      error : function(XMLHttpRequest, textStatus, errorThrown) {
         $('#discharge').val('')
         $('#date').val('')
      }
    })

    //Load USGS Site Information
    $.ajax({
      url: "http://waterservices.usgs.gov/nwis/site/?format=rdb,1.0&sites="+usgsSite+"&siteOutput=expanded",
      dataType: 'text',
      data: '',
      async: false,
      success: function(data){
      var siteInfo = getRDBSiteInfo(data,['drain_area_va'])
        $('#watershedArea').val(siteInfo.drain_area_va)

      }
    })

    //Load AHPS XML Data
    $.ajax({
      url: "get_ahps_data.php?site="+nwsLid,
      dataType: 'XML',
      data: '',
      async: false,
      success: function(XML){
            var tmpData = Array()
    NWS.sources.push('NWS');
        NWS.forecastIssued =  '(Issued: '+Highcharts.dateFormat('%l %P %b %e',new Date($(XML).find('forecast').attr('issued')).getTime())+')';
        NWS.nwsTitle = $(XML).find("site").attr("name");
        $(XML).find("sigstages").each(function(){
          $(this).children().each(function(){
            if($(this).text()){
               NWS.stage[this.nodeName].value = parseFloat($(this).text())
               NWS.maxY = Math.round(parseFloat($(this).text()) + 4)
            }
          })
        })


      var stageArray = [];
      var dischargeArray = [];
      $(XML).find("forecast").each(function(){
          $(this).children().each(function(){
             var d = new Date($(this).find("valid").text()).getTime();
             var stage = $(this).find("primary").text()
             var discharge = $(this).find("secondary").text()
             var mult = 1;
             if($(XML).find('secondary').attr('units') == 'kcfs') mult = 1000
             stageArray.push([parseInt(d),parseFloat(stage)])
             dischargeArray.push([parseInt(d),Math.round(parseFloat(discharge)*mult)])

          })
        })
      NWS.stageSeries.push({
      data : stageArray,
      name : 'NWS Forecast Data'+NWS.forecastIssued,
      color: 'purple',
      nwsType: 'forecast'
     });
        NWS.dischargeSeries.push({
      data : dischargeArray,
      name : 'NWS Forecast Data'+NWS.forecastIssued,
      color: 'purple',
      nwsType: 'forecast'
     });

    stageArray = [];
        dischargeArray = [];
        $(XML).find("observed").each(function(){
            $(this).children().each(function(){
        var d = new Date($(this).find("valid").text()).getTime();
        var stage = $(this).find("primary").text()
        var discharge = $(this).find("secondary").text()
        stageArray.unshift([parseInt(d),parseFloat(stage)])
        dischargeArray.unshift([parseInt(d),parseFloat(discharge)*1000])
      })
        })
    NWS.stageSeries.push({
      data : stageArray,
      name : 'NWS Observed Data',
      color: 'blue',
      nwsType: 'observed'
     });


    NWS.dischargeSeries.push({
      data :  dischargeArray,
      name : 'NWS Observed Data',
      color: 'blue',
      nwsType: 'observed'
     });



      graphStage(NWS);
      graphDischarge(NWS);

        },
      timeout: 10000 // sets timeout to 3 seconds

    })
  })

function graphStage(NWS){
    var title;
    if(NWS.usgsTitle.length>0){
        title = NWS.usgsTitle;
    }else{
      title = NWS.nwsTitle;
    }

  stagechartobj= {
      credits: {
        enabled: false
        },
        chart: {
      renderTo: 'stagegraph',
      zoomType: 'xy',
      marginTop: 80,
      marginBottom:175,
      marginLeft:80,
      events: {
        load: function (event) {
          addNWSRender(this);
          addNWSBands(this,NWS);
        }
      }
    },
    title: {
      text: title,
      x: -20 //center
    },
    subtitle: {
      text: 'Sources: '+NWS.sources.join(','),
      x: -20
    },
    xAxis: {
      max : new Date().getTime()+3600000*24*4,
      min : new Date().getTime()-3600000*24*7,
      type: 'datetime',
      tickPixelInterval: 25,
      gridLineColor: "#E8E8E8",
      gridLineWidth: 1,
      labels: {
        step: 2,
        staggerLines: 1,
        formatter: function () {
          return Highcharts.dateFormat('%l %P<br>%b %e',this.value)
        }
      },
      plotLines: [{
        color: 'black', // Color value
        dashStyle: 'dot', // Style of the plot line. Default to solid
        value: new Date().getTime(), // Value of where the line will appear
        width: '2', // Width of the line
        zIndex:10
      }],
      plotBands: [{
            from: 0,
            to: new Date().getTime(),
            color: 'rgba(248,248,248,0.4)',
            zIndex:5,
            id: 'plot-band-observed'
          }]

    },
    yAxis: [{
      title: {
        text: 'River Stage (ft)',
        style: {
          color: 'Black',
          fontWeight: 'bold',
          fontSize: '14px'
        }
      },
      labels:{
        x: -5
      },
      tickPositioner: function () {
        var positions = []
        tick = Math.floor(this.dataMin)-2
        max = NWS.maxY
        increment = 1
        if((max-tick)>20) increment = 2
        for (tick; tick - increment <= max; tick += increment) {
          positions.push(tick);
        }
        return positions
      }

    }],
    tooltip: {
      valueSuffix: ' ft'
    },
    legend: {
      align: 'center',
      verticalAlign: 'bottom',
      borderWidth: 0,
      y:-100,
      x:25
    },
    series: NWS.stageSeries

  }
  chart = new Highcharts.Chart(stagechartobj);
}

function graphDischarge(NWS){
    ////////////////////////////////////Discharge Graph////////////////////////////////
    dischargechartobj= {
        credits: {
            enabled: false
        },
        chart: {
            renderTo: 'dischargegraph',
            zoomType: 'xy',
            marginTop: 50,
            marginBottom:100,
            marginLeft:80,
            events: {
                load: function (event) {

                    addStats(this,usgsSite,this.xAxis[0].getExtremes);
                }
            }
        },
        title: {
            text: null,
            x: -20 //center
        },
        xAxis: {
            max : new Date().getTime()+3600000*24*4,
            min : new Date().getTime()-3600000*24*7,
            type: 'datetime',
            tickPixelInterval: 25,
            gridLineColor: "#E8E8E8",
            gridLineWidth: 1,
            labels: {
                step: 2,
                staggerLines: 1,
                formatter: function () {
                    return Highcharts.dateFormat('%l %P<br>%b %e',this.value)
                }
            },
            plotLines: [{
                color: 'black', // Color value
                dashStyle: 'dot', // Style of the plot line. Default to solid
                value: new Date().getTime(), // Value of where the line will appear
                width: '2', // Width of the line
                zIndex:2
            }],
        },
        yAxis: [{
            title: {
                text: 'River Discharge (cfs)',
                style: {
                    color: 'Black',
                    fontWeight: 'bold',
                    fontSize: '14px'
                }
            },
            labels:{
                x: -5
            },
            type: 'logarithmic',
        }],
        tooltip: {
            valueSuffix: ' cfs',
            //shared: true,
            crosshairs: true,
        },
        legend: {
          align: 'center',
          verticalAlign: 'bottom',
          borderWidth: 0,
          x:10
        },
        series: NWS.dischargeSeries
    }
    dischargechart = new Highcharts.Chart(dischargechartobj);
  }

function graphRating(NWS){
    ////////////////////////////////////Rating Graph////////////////////////////////
    ratingchartobj= {
      credits: {
          enabled: false
        },
        chart: {
          renderTo: 'ratinggraph',
          zoomType: 'xy',
          marginTop: 50,
          marginBottom:100,
          marginLeft:80,
      },
        title: {
        text: null,
        x: -20 //center
      },
        xAxis: {
            type: 'logarithmic',
            crosshair: {
                width: 1,
                snap:false,
                color: 'red'
            },
            type: 'linear',
            title: {
                text: 'River Discharge (ft)',
                style: {
                    color: 'Black',
                    fontWeight: 'bold',
                    fontSize: '14px'
                }
            },
        },
        yAxis: [{
            crosshair: {
                width: 1,

                color: 'red'
            },
            title: {
                text: 'River Stage (ft)',
                style: {
                    color: 'Black',
                    fontWeight: 'bold',
                    fontSize: '14px'
                }
            },
            labels:{
                x: -5
            },
            type: 'linear',
        }],
        tooltip: {
            headerFormat: 'Rating Curve<br>',
            positioner: function () {
                    return { x: 20, y: 20 };
                }
        },
        legend: {
            align: 'center',
            verticalAlign: 'bottom',
            borderWidth: 0,
            x:10
        },
        series: NWS.ratingsSeries
    }
    ratingchart = new Highcharts.Chart(ratingchartobj);
  }


$('#dischargeAxis').change(function() {
  if($(this).is(':checked')){
    $('#dischargegraph').highcharts().yAxis[0].update({ type: 'logarithmic'});
    }
    else
    {
      $('#dischargegraph').highcharts().yAxis[0].update({ type: 'linear'});
  }
});

$('#ratingLog').change(function() {
  if($(this).is(':checked')){
        $('#ratinggraph').highcharts().yAxis[0].update({ type: 'logarithmic'});
        $('#ratinggraph').highcharts().xAxis[0].update({ type: 'logarithmic'});
    }
    else
    {
        $('#ratinggraph').highcharts().yAxis[0].update({ type: 'linear'});
        $('#ratinggraph').highcharts().xAxis[0].update({ type: 'linear'});
  }
});

$(function () {

    /**
     * This plugin extends Highcharts in two ways:
     * - Use HTML5 canvas instead of SVG for rendering of the heatmap squares. Canvas
     *   outperforms SVG when it comes to thousands of single shapes.
     * - Add a K-D-tree to find the nearest point on mouse move. Since we no longer have SVG shapes
     *   to capture mouseovers, we need another way of detecting hover points for the tooltip.
     */
    (function (H) {
        var Series = H.Series,
            each = H.each;

        /**
         * Create a hidden canvas to draw the graph on. The contents is later copied over
         * to an SVG image element.
         */
        Series.prototype.getContext = function () {
            if (!this.canvas) {
                this.canvas = document.createElement('canvas');
                this.canvas.setAttribute('width', this.chart.chartWidth);
                this.canvas.setAttribute('height', this.chart.chartHeight);
                this.image = this.chart.renderer.image('', 0, 0, this.chart.chartWidth, this.chart.chartHeight).add(this.group);
                this.ctx = this.canvas.getContext('2d');
            }
            return this.ctx;
        };

        /**
         * Draw the canvas image inside an SVG image
         */
        Series.prototype.canvasToSVG = function () {
            this.image.attr({ href: this.canvas.toDataURL('image/png') });
        };

        /**
         * Wrap the drawPoints method to draw the points in canvas instead of the slower SVG,
         * that requires one shape each point.
         */
        H.wrap(H.seriesTypes.heatmap.prototype, 'drawPoints', function () {

            var ctx = this.getContext();

            if (ctx) {

                // draw the columns
                each(this.points, function (point) {
                    var plotY = point.plotY,
                        shapeArgs;

                    if (plotY !== undefined && !isNaN(plotY) && point.y !== null) {
                        shapeArgs = point.shapeArgs;

                        ctx.fillStyle = point.pointAttr[''].fill;
                        ctx.fillRect(shapeArgs.x, shapeArgs.y, shapeArgs.width, shapeArgs.height);
                    }
                });

                this.canvasToSVG();

            } else {
                this.chart.showLoading('Your browser doesn\'t support HTML5 canvas, <br>please use a modern browser');

                // Uncomment this to provide low-level (slow) support in oldIE. It will cause script errors on
                // charts with more than a few thousand points.
                // arguments[0].call(this);
            }
        });
        H.seriesTypes.heatmap.prototype.directTouch = false; // Use k-d-tree
    }(Highcharts));

});


function graphHeatQ(NWS){
    var title;
    if(NWS.usgsTitle.length>0){
        title = NWS.usgsTitle;
    }else{
      title = NWS.nwsTitle;
    }

    var start;
    heatchartobj= {
        chart: {
            renderTo: 'heatMapQ',
            type: 'heatmap',
            zoomType: 'xy'
        },
        credits: {
            enabled: false
        },
        title: {
            text: title+' DAILY DATA'
        },
        plotOptions:{
            series:{
                point: {
				    events: {
					    mouseOver: function() {
						    ratingchart.xAxis[0].removePlotLine('vert_loc');
                            ratingchart.xAxis[0].addPlotLine({
                                value: this.value,
                                color: 'red',
                                width: 1,
                                id: 'vert_loc'
                            });
						},
						mouseOut: function() {
						    ratingchart.xAxis[0].removePlotLine('vert_loc');
                        }

				    }
                }
            }
       },

       xAxis: {
            title: {
                text: 'Day of Year'
            },
            min: 0,
            max: 365,
            tickPositions: [1,32, 60,91, 121,152, 182,213, 244,274,305,335],
            labels: {
                align: 'left',
                x: 5,
                y: 14,
                formatter: function (){
                    return Highcharts.dateFormat(' %b ',dateFromDay('2015',this.value));
                 }
            },
            showLastLabel: true,
            tickLength: 10
        },

        yAxis: {
            title: {
                text: 'Year'
            },
            minPadding: 0,
            maxPadding: 0,
        },

        colorAxis: {
            type: 'logarithmic',
            stops: [
                [0 ,   '#FF0000'],
                [0.3, '#FFCC33'],
                [0.8, '#66CCFF'],
                [1 ,   '#3300CC']
            ],
            startOnTick: false,
            endOnTick: false
        },
        tooltip: {
            headerFormat: 'Daily Discharge<br>',
            formatter: function () {
                return '<b>' + Highcharts.dateFormat('%b %e %Y',dateFromDay(this.point.y,this.point.x))+' - '+numberWithCommas(this.point.value) + ' cfs </b>';
            },
            //pointFormat: dateFromDay(this.point.y,this.point.x),
            positioner: function () {
                    return { x: 500, y: 28 };
             }
        },
        series: [{
            data: NWS.dailyUSGS[0].data,
            borderWidth: 0,
            nullColor: '#EFEFEF',
            //colsize: 24 * 36e5, // one day
//            tooltip: {
//               headerFormat: 'Daily Discharge Values<br/>',
//                pointFormat: 'Year:{point.y} Day:{point.x}  <b>{point.value} cfs </b>'
//            },
            turboThreshold: Number.MAX_VALUE // #3404, remove after 4.0.5 release
        }]

    }


    heatchart = new Highcharts.Chart(heatchartobj);

}


</script>
</body>
</html>

