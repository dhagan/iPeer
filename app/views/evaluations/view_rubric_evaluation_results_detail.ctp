<?php echo $html->script('ricobase');
echo $html->script('ricoeffects');
echo $html->script('ricoanimation');
echo $html->script('ricopanelcontainer');
echo $html->script('ricoaccordion');
?>
<div class="content-container">
<!-- Render Event Info table -->
<?php echo $this->element('evaluations/view_event_info', array('controller'=>'evaluations', 'event'=>$event));?>
<?php echo $this->element('evaluations/summary_info', array('controller'=>'evaluations', 'event'=>$event));?>

<!-- summary table -->
<form name="evalForm" id="evalForm" method="POST" action="<?php echo $html->url('markEventReviewed') ?>">
    <input type="hidden" name="event_id" value="<?php echo $event['Event']['id']?>" />
    <input type="hidden" name="group_id" value="<?php echo $event['Group']['id'] ?>" />
    <input type="hidden" name="course_id" value="<?php echo $event['Event']['course_id']?>" />
    <input type="hidden" name="group_event_id" value="<?php echo $event['GroupEvent']['id']?>" />
    <input type="hidden" name="display_format" value="Detail" />
<table class="standardtable">
    <?php echo $html->tableHeaders($this->Evaluation->getRubricSummaryTableHeader($rubric['Rubric']['total_marks'], $rubricCriteria));?>
    <?php echo $html->tableCells($this->Evaluation->getRubricSummaryTable($summaryMembers, $scoreRecords, $memberScoreSummary, $penalties, $rubric['Rubric']['total_marks'])); ?>
    <tr align="center"><td colspan="<?php echo $rubric['Rubric']['criteria']+2; ?>">
        <?php
            if ($event['GroupEvent']['marked'] == "reviewed") {
                echo "<input class=\"reviewed\" type=\"submit\" name=\"mark_not_reviewed\" value=\" ".__('Mark Peer Evaluations as Not Reviewed', true)."\" />";
            } else {
                echo "<input class=\"reviewed\" type=\"submit\" name=\"mark_reviewed\" value=\" ".__('Mark Peer Evaluations as Reviewed', true)."\" />";
            }
        ?>
    </td></tr>
</table>
</form>

<h3><?php __('Evaluation Results')?></h3>
<div id='rubric_result'>

<?php
$rowspan = count($groupMembersNoTutors) + 3;
$numerical_index = 1;  //use numbers instead of words; get users to refer to the legend
$color = array("", "#FF3366","#ff66ff","#66ccff","#66ff66","#ff3333","#00ccff","#ffff33");
$membersAry = array();  //used to format result (students)
$withTutorsAry = array(); //used to format result (students,tutors)
$groupAve = 0;
$groupAverage = array_fill(1, $rubric['Rubric']['criteria'], 0);
$aveScoreSum = 0;
?>
<!-- summary table -->
<?php
if ($groupMembersNoTutors) {
    foreach ($groupMembersNoTutors as $member) {
        $membersAry[$member['User']['id']] = $member;
        if (isset($memberScoreSummary[$member['User']['id']]['received_ave_score'])) {
            $avgScore = $memberScoreSummary[$member['User']['id']]['received_ave_score'];
            $penalty = number_format(($penalties[$member['User']['id']] / 100) * $avgScore, 2);
            $penalty_percent = $penalties[$member['User']['id']] / 100;
            $questionIndex = 0;
            foreach ($scoreRecords[$member['User']['id']]['rubric_criteria_ave'] AS $criteriaAveIndex => $criteriaAveGrade) {
                $scaledQuestionGrade = $criteriaAveGrade * (1 - $penalty_percent);
                $groupAverage[$criteriaAveIndex] += $scaledQuestionGrade;
                $deduction = $criteriaAveGrade * $penalty_percent;
                $questionIndex++;
            }
        }
        // for calculating average percentage per question (ratio)
        $ratio = 0;
        for ($i = 0; $i < $rubric['Rubric']["criteria"]; $i++) {
            if (!empty($scoreRecords[$member['User']['id']]['rubric_criteria_ave']))
                $ratio += $scoreRecords[$member['User']['id']]['rubric_criteria_ave'][$i+1] / $rubricCriteria[$i]['multiplier'];
        }
        $avgPerQues[$member['User']['id']] = $ratio /  $rubric['Rubric']['criteria'];
        if (isset($memberScoreSummary[$member['User']['id']]['received_ave_score'])) {
            $finalAvgScore = $avgScore - $penalty;
            $aveScoreSum += $finalAvgScore;
            $receviedAvePercent = $finalAvgScore / $rubric['Rubric']['total_marks'] * 100;
            $membersAry[$member['User']['id']]['received_ave_score'] = $memberScoreSummary[$member['User']['id']]['received_ave_score'];
            $membersAry[$member['User']['id']]['received_ave_score_%'] = $receviedAvePercent;
        }
    }
    $groupAve = number_format($aveScoreSum / count($groupMembersNoTutors), 2);
} ?>
<?php
if ($groupMembers) {
    foreach ($groupMembers as $member) {
        $withTutorsAry[$member['User']['id']]['first_name'] = $member['User']['first_name'];
        $withTutorsAry[$member['User']['id']]['last_name'] = $member['User']['last_name'];
    }
}?>

<div id="accordion">
    <?php $i = 0;
    foreach($groupMembersNoTutors as $row):
        $user = $row['User']; ?>
        <div id="panel<?php echo $user['id']?>">
        <div id="panel<?php echo $user['id']?>Header" class="panelheader">
            <?php echo __('Evaluatee: ', true).$user['first_name']." ".$user['last_name']?>
        </div>
        <div style="height: 200px;text-align: center;" id="panel1Content" class="panelContent">
            <br><b><?php
                $scaled = $membersAry[$user['id']]['received_ave_score'] * (1 - ($penalties[$user['id']] / 100));
                $deduction = number_format($membersAry[$user['id']]['received_ave_score'] * ($penalties[$user['id']] / 100), 2);
                $percent = number_format($membersAry[$user['id']]['received_ave_score_%']);

                echo __(" (Number of Evaluator(s): ",true).count($evalResult[$user['id']]).")<br/>";
                echo __("Final Total: ",true).number_format($memberScoreSummary[$row['User']['id']]['received_ave_score'],2);
                $penalties[$user['id']] > 0 ? $penaltyAddOn = ' - '."<font color=\"red\">".$deduction."</font> = ".number_format($scaled, 2) :
                    $penaltyAddOn = '';
                echo $penaltyAddOn.' ('.$percent.'%)';
                if (isset($membersAry[$user['id']]['received_ave_score'])) {
                    $memberAvgScore = number_format($avgPerQues[$user['id']], 2);
                    $memberAvgScoreDeduction = number_format($avgPerQues[$user['id']] * $penalties[$user['id']] / 100, 2);
                    $memberAvgScoreScaled = number_format($avgPerQues[$user['id']] * (1 - ($penalties[$user['id']] / 100)), 2);
                    $memberAvgScorePercent = number_format($avgPerQues[$user['id']] * (1 - ($penalties[$user['id']] / 100)) * 100);
                } else {
                    $memberAvgScore = '-';
                    $memberAvgScorePercent = '-';
                }
                if ($scaled == $groupAve) {
                    echo "&nbsp;&nbsp;((".__("Same Mark as Group Average", true)." ))<br>";
                } else if ($scaled < $groupAve) {
                    echo "&nbsp;&nbsp;<font color='#cc0033'><< ".__('Below Group Average', true)." >></font><br>";
                } else if ($scaled > $groupAve) {
                    echo "&nbsp;&nbsp;<font color='#000099'><< ".__('Above Group Average', true)." >></font><br>";
                }
                echo __("Average Percentage Per Question: ", true);
                echo $memberAvgScore;
                $penalties[$user['id']] > 0 ? $penaltyAddOn = ' - '."<font color=\"red\">".$memberAvgScoreDeduction."</font> = ".$memberAvgScoreScaled :
                    $penaltyAddOn = '';
                echo $penaltyAddOn.' ('.$memberAvgScorePercent.'%)<br>';
                $penalties[$user['id']] > 0 ? $penaltyNotice = 'NOTE: <font color=\'red\'>'.$penalties[$user['id']].'% </font>Late Penalty<br>' :
                    $penaltyNotice = '<br>';
                echo $penaltyNotice;
                ?> </b>
            <br><br>
        <table class="standardtable">
            <tr>
                <th width="100" valign="top"><?php __('Evaluator')?></th>
                <?php for ($i=1; $i<=$rubric['Rubric']["criteria"]; $i++): ?>
                    <th><strong>(<?php echo $i?>)</strong>
                        <?php echo $rubricCriteria[$i-1]['criteria'];?>
                    </th>
                <?php endfor; ?>
            </tr>
            <?php
            //Retrieve the individual rubric detail
            if (isset($evalResult[$user['id']])) {

                $memberResult = $evalResult[$user['id']];

                foreach ($memberResult AS $row): $memberRubric = $row['EvaluationRubric'];
                    $evalutor = $withTutorsAry[$memberRubric['evaluator']];
                    echo "<tr class=\"tablecell2\">";
                    echo "<td width='15%'>".$evalutor['first_name']." ".$evalutor['last_name'] ."</td>";

                    $resultDetails = $memberRubric['details'];
                    foreach ($resultDetails AS $detail) : $rubDet = $detail['EvaluationRubricDetail'];
                    $i = 0;
                    echo '<td valign="middle">';
                    echo "<br />";
                    //Points Detail
                    echo "<strong>".__('Points', true).": </strong>";
                    if (isset($rubDet)) {
                        $lom = $rubDet["selected_lom"];
                        $empty = $rubric["Rubric"]["lom_max"];
                        for ($v = 0; $v < $lom; $v++) {
                            echo $html->image('evaluations/circle.gif', array('align'=>'middle', 'vspace'=>'1', 'hspace'=>'1','alt'=>'circle'));
                            $empty--;
                        }
                        for ($t=0; $t < $empty; $t++) {
                            echo $html->image('evaluations/circle_empty.gif', array('align'=>'middle', 'vspace'=>'1', 'hspace'=>'1','alt'=>'circle_empty'));
                        }
                        echo "<br />";
                    } else {
                        echo "n/a<br />";
                    }
                //Grade Detail
                echo "<strong>".__('Grade:', true)." </strong>";
                if (isset($rubDet)) {
                    echo $rubDet["grade"] . " / " . $rubricCriteria[$i]['multiplier'] . "<br />";
                    $i++;
                } else {
                    echo "n/a<br />";
                }
                //Comments
                echo "<br/><strong>".__('Comment:', true)." </strong>";
                if (isset($rubDet)) {
                    echo $rubDet["criteria_comment"];
                } else {
                    echo "n/a<br />";
                }
                echo "</td>";
            endforeach;

            echo "</tr>";
            //General Comment
            echo "<tr class=\"tablecell2\">";
            echo "<td></td>";
            $col = $rubric['Rubric']['criteria'] + 1;
            echo "<td colspan=".$col.">";
            echo "<strong>".__('General Comment:', true)." </strong><br>";
            echo $memberRubric['comment'];
            echo "<br><br></td>";
            echo "</tr>";
        endforeach;
        } ?>
    </table>
    <?php
        echo "<br>";
        //Grade Released
        if (isset($scoreRecords[$user['id']]['grade_released']) && $scoreRecords[$user['id']]['grade_released']) {?>

            <input type="button" name="UnreleaseGrades" value="<?php __('Unrelease Grades')?>" onClick="location.href='<?php echo $this->webroot.$this->theme.'evaluations/markGradeRelease/'.$event['Event']['id'].';'.$event['Group']['id'].';'.$user['id'].';'.$event['GroupEvent']['id'].';0'; ?>'">
        <?php } else {?>
            <input type="button" name="ReleaseGrades" value="<?php __('Release Grades')?>" onClick="location.href='<?php echo $this->webroot.$this->theme.'evaluations/markGradeRelease/'.$event['Event']['id'].';'.$event['Group']['id'].';'.$user['id'].';'.$event['GroupEvent']['id'].';1'; ?>'">
        <?php }

        //Comment Released
        if (isset($scoreRecords[$user['id']]['comment_released']) && $scoreRecords[$user['id']]['comment_released']) {?>
            <input type="button" name="UnreleaseComments" value="<?php __('Unrelease Comments')?>" onClick="location.href='<?php echo $this->webroot.$this->theme.'evaluations/markCommentRelease/'.$event['Event']['id'].';'.$event['Group']['id'].';'.$user['id'].';'.$event['GroupEvent']['id'].';0'; ?>'">
        <?php } else { ?>
            <input type="button" name="ReleaseComments" value="<?php __('Release Comments')?>" onClick="location.href='<?php echo $this->webroot.$this->theme.'evaluations/markCommentRelease/'.$event['Event']['id'].';'.$event['Group']['id'].';'.$user['id'].';'.$event['GroupEvent']['id'].';1'; ?>'">
        <?php } ?>
    </div>
    </div>

    <?php $i++;?>
    <?php endforeach; ?>
</div>

<script type="text/javascript"> new Rico.Accordion( 'accordion',
                                    {panelHeight:500,
                                    hoverClass: 'mdHover',
                                    selectedClass: 'mdSelected',
                                    clickedClass: 'mdClicked',
                                    unselectedClass: 'panelheader'});
</script>
</div>
</div>
