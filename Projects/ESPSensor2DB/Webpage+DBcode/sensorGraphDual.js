$(document).ready(function(){
  console.log("sensorGraphDual.js loaded");

  // Humidity chart
  $.ajax({
    url:"Chartjs/sensorDataHum.php",
    method:"GET",
    dataType:"json",
    success:function(data){
      const labels=data.map(r=>r.time_received);
      const hums=data.map(r=>parseFloat(r.humidity));
      const ctx=document.getElementById('humChart').getContext('2d');
      new Chart(ctx,{
        type:'line',
        data:{
          labels,
          datasets:[{
            label:'Humidity (%)',
            data:hums,
            borderColor:'rgba(54,162,235,1)',
            backgroundColor:'rgba(54,162,235,0.2)',
            fill:true,tension:0.25
          }]
        },
        options:{
          plugins:{title:{display:false}},
          scales:{
            x:{title:{display:true,text:'Time'}},
            y:{beginAtZero:false,title:{display:true,text:'Humidity (%)'}}
          }
        }
      });
    },
    error:(x,s,e)=>console.error("Humidity AJAX error:",s,e)
  });

  // Temperature chart
  $.ajax({
    url:"Chartjs/sensorDataTemp.php",
    method:"GET",
    dataType:"json",
    success:function(data){
      const labels=data.map(r=>r.time_received);
      const temps=data.map(r=>parseFloat(r.temperature));
      const ctx=document.getElementById('tempChart').getContext('2d');
      new Chart(ctx,{
        type:'line',
        data:{
          labels,
          datasets:[{
            label:'Temperature (°C)',
            data:temps,
            borderColor:'rgba(255,99,132,1)',
            backgroundColor:'rgba(255,99,132,0.2)',
            fill:true,tension:0.25
          }]
        },
        options:{
          plugins:{title:{display:false}},
          scales:{
            x:{title:{display:true,text:'Time'}},
            y:{beginAtZero:false,title:{display:true,text:'Temperature (°C)'}}
          }
        }
      });
    },
    error:(x,s,e)=>console.error("Temperature AJAX error:",s,e)
  });
});
