import { ChangeDetectionStrategy, Component } from '@angular/core';
import { BusinessContentComponent } from '../components/business-content/business-content.component';
import { BusinessGridComponent } from '../components/business-grid/business-grid.component';

@Component({
  selector: 'app-business',
  imports: [BusinessContentComponent, BusinessGridComponent],
  standalone:true,
  templateUrl: './business-section.component.html',
  styleUrl: './business-section.component.scss',
  changeDetection: ChangeDetectionStrategy.OnPush,
})
export class BusinessSectionComponent {}
