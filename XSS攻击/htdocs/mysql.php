<?php
	$id=$_POST["id"];
	$name=$_POST["name"];
	$con=mysqli_connect("localhost","root","");
	mysqli_select_db($con,"test");
	
	$sql="insert into table value ($id,'$name')";
	$result=mysqli_query($con,$sql);
    if(!$result){
        echo "插入失败";
        }
    else { echo "插入成功"; }
?>