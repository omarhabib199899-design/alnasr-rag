import { ChangeDetectionStrategy, Component } from '@angular/core';
import { AboutContentComponent } from '../components/about-content.component/about-content.component';
import { FeatureGridComponent } from '../components/feature-grid.component/feature-grid.component';
import { StatsGridComponent } from '../components/stats-grid.component/stats-grid.component';

@Component({
  selector: 'app-about',
  imports: [AboutContentComponent, FeatureGridComponent, StatsGridComponent],
  standalone:true,
  templateUrl: './about-section.component.html',
  styleUrl: './about-section.component.scss',
  changeDetection: ChangeDetectionStrategy.OnPush,
})
export class AboutSectionComponent {}
